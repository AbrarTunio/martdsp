/**
 * Chart.js behind an Alpine component.
 *
 * The server sends a small description of the chart — its type, labels, and
 * datasets with money in paisa — rather than a Chart.js config, so no PHP has
 * to know how Chart.js is set up and every chart in the app looks the same.
 *
 * Chart.js is only fetched on a page that has a chart on it, and each chart
 * redraws itself when the theme switches between light and dark.
 */
import money from './money';

const palette = {
    brand: '#059669',
    sky: '#0284c7',
    amber: '#d97706',
    violet: '#7c3aed',
    pink: '#db2777',
    lime: '#65a30d',
    red: '#dc2626',
    gray: '#9ca3af',
};

/** The order colours are handed out in when a dataset does not ask for one. */
const series = ['brand', 'sky', 'amber', 'violet', 'pink', 'lime', 'red', 'gray'];

const numbers = new Intl.NumberFormat('en-PK');

let loading = null;

function loadChartJs() {
    loading ??= import('chart.js').then((module) => {
        const { Chart } = module;

        Chart.register(
            module.BarController,
            module.LineController,
            module.DoughnutController,
            module.BarElement,
            module.LineElement,
            module.PointElement,
            module.ArcElement,
            module.CategoryScale,
            module.LinearScale,
            module.Tooltip,
            module.Legend,
            module.Filler,
        );

        return Chart;
    });

    return loading;
}

function isDark() {
    return document.documentElement.classList.contains('dark');
}

function colour(name, index) {
    return palette[name] ?? palette[series[index % series.length]];
}

/**
 * Turn the server's description into a Chart.js config.
 */
function config(spec, dark) {
    const text = dark ? '#9ca3af' : '#6b7280';
    const grid = dark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';
    const doughnut = spec.type === 'doughnut';
    const horizontal = Boolean(spec.horizontal);
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const short = (value) => (spec.money ? money.rounded(value) : numbers.format(value));
    const exact = (value) => (spec.money ? money.withSymbol(value) : numbers.format(value));

    const datasets = spec.datasets.map((set, index) => {
        if (doughnut) {
            return {
                label: set.label,
                data: set.data,
                backgroundColor: set.data.map((_, slice) => colour(null, slice)),
                borderColor: dark ? '#111827' : '#ffffff',
                borderWidth: 2,
            };
        }

        const type = set.type ?? spec.type;
        const tint = colour(set.color, index);

        return {
            type,
            label: set.label,
            data: set.data,
            backgroundColor: type === 'line' ? `${tint}1f` : tint,
            borderColor: tint,
            borderWidth: type === 'line' ? 2 : 0,
            borderRadius: type === 'bar' ? 4 : 0,
            borderDash: set.dashed ? [5, 4] : [],
            fill: type === 'line' && Boolean(set.fill),
            tension: 0.3,
            pointRadius: spec.labels.length > 31 ? 0 : 2,
            maxBarThickness: 36,
            order: type === 'line' ? 0 : 1,
        };
    });

    const categoryAxis = {
        grid: { display: false },
        border: { display: false },
        ticks: {
            color: text,
            maxRotation: 0,
            autoSkip: true,
            autoSkipPadding: 10,
            callback(value) {
                const label = String(this.getLabelForValue(value));

                return horizontal && label.length > 20 ? `${label.slice(0, 19)}…` : label;
            },
        },
    };

    const valueAxis = {
        beginAtZero: true,
        grid: { color: grid },
        border: { display: false },
        ticks: { color: text, maxTicksLimit: 6, callback: (value) => short(value) },
    };

    return {
        type: doughnut ? 'doughnut' : spec.type,
        data: { labels: spec.labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: horizontal ? 'y' : 'x',
            animation: reduceMotion ? false : { duration: 400 },
            interaction: doughnut ? { mode: 'nearest', intersect: true } : { mode: 'index', intersect: false },
            cutout: doughnut ? '62%' : undefined,
            plugins: {
                legend: {
                    display: doughnut || spec.datasets.length > 1,
                    position: 'bottom',
                    labels: { color: text, boxWidth: 10, boxHeight: 10, usePointStyle: true },
                },
                tooltip: {
                    callbacks: {
                        label(context) {
                            if (doughnut) {
                                return ` ${context.label}: ${exact(context.parsed)}`;
                            }

                            const value = horizontal ? context.parsed.x : context.parsed.y;

                            return ` ${context.dataset.label}: ${exact(value)}`;
                        },
                    },
                },
            },
            scales: doughnut
                ? {}
                : {
                    [horizontal ? 'y' : 'x']: categoryAxis,
                    [horizontal ? 'x' : 'y']: valueAxis,
                },
        },
    };
}

export default function chart(spec) {
    /* Kept out of Alpine's reactive data on purpose: Chart.js and Alpine's
       proxies watching the same object would chase each other forever. */
    let instance = null;
    let observer = null;

    return {
        async init() {
            const Chart = await loadChartJs();

            const draw = () => {
                instance?.destroy();
                instance = new Chart(this.$refs.canvas, config(spec, isDark()));
            };

            draw();

            observer = new MutationObserver(draw);
            observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        },

        destroy() {
            observer?.disconnect();
            instance?.destroy();
        },
    };
}
