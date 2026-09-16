import Alpine from 'alpinejs';
import { animate, stagger } from 'motion';

import money from './money';
import packagingBuilder from './packaging';
import stockAdjustment from './stock-adjustment';
import stockTake from './stock-take';
import purchaseEntry from './purchase-entry';
import purchaseReturn from './purchase-return';
import saleReturn from './sale-return';
import pos from './pos';
import drawerCount from './drawer-count';
import chart from './charts';
import insights from './insights';
import registerDirectives from './directives';

window.Alpine = Alpine;

/** Exposed for Blade-inlined Alpine expressions that need rupee formatting. */
window.money = money;

/** Motion One is used sparingly — see directives.js. */
window.motion = { animate, stagger };

registerDirectives(Alpine);

/** The product form's carton/box/sachet entry. */
Alpine.data('packagingBuilder', packagingBuilder);

/** The scan-and-count stock correction form. */
Alpine.data('stockAdjustment', stockAdjustment);
Alpine.data('stockTake', stockTake);

/** The scan-as-it-comes-off-the-van delivery form. */
Alpine.data('purchaseEntry', purchaseEntry);

/** Goods going back to a supplier. */
Alpine.data('purchaseReturn', purchaseReturn);

/** Goods a customer brought back off a bill. */
Alpine.data('saleReturn', saleReturn);

/** The till. */
Alpine.data('pos', pos);

/** The note-by-note count at the end of a shift. */
Alpine.data('drawerCount', drawerCount);

/** The "Explain this page" panel. */
Alpine.data('insights', insights);

/** Every chart, on the dashboard and the reports. Chart.js loads on first use. */
Alpine.data('chart', chart);

Alpine.start();
