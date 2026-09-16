/**
 * The "Explain this page" panel.
 *
 * The note is written on the server, so this only opens the drawer, posts
 * the page it sits on, and drops the returned panel in. The first press
 * fetches; pressing Refresh asks again, past whatever was saved earlier.
 */
export default function insights(config) {
    return {
        open: false,
        loading: false,
        error: '',
        html: '',

        /** Open, and fetch the first time only. */
        async show() {
            this.open = true;

            if (this.html === '' && !this.loading) {
                await this.load(false);
            }
        },

        close() {
            this.open = false;
        },

        async refresh() {
            await this.load(true);
        },

        async load(refresh) {
            this.loading = true;
            this.error = '';

            try {
                const response = await fetch(config.url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': config.token,
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'text/html',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ ...config.scope, refresh }),
                });

                if (response.status === 429) {
                    throw new Error(config.strings.tooMany);
                }

                if (!response.ok) {
                    throw new Error(config.strings.failed);
                }

                this.html = await response.text();
            } catch (error) {
                this.error = error.message || config.strings.failed;
            } finally {
                this.loading = false;
            }
        },
    };
}
