import './bootstrap';
import './ui';
import './pwa';

/*
 * The follow-up calendar (FullCalendar). Its code is loaded only on the page that shows it:
 *
 *     <div x-data="followupCalendar({ offsetMinutes: 330 })"> … <div x-ref="calendar"></div> … </div>
 */
window.followupCalendar = (options = {}) => ({
    calendar: null,
    message: '',
    failed: false,

    async init() {
        const { mountFollowupCalendar } = await import('./followup-calendar');

        this.calendar = mountFollowupCalendar(this.$refs.calendar, this.$wire, options, (message, failed = false) => {
            this.message = message;
            this.failed = failed;
        });
    },

    refetch() {
        this.message = '';
        this.calendar?.refetchEvents();
    },

    destroy() {
        this.calendar?.destroy();
        this.calendar = null;
    },
});

/*
 * Charts (Chart.js, loaded only on a page that has one):
 *
 *     <div x-data="analyticsChart(@js($spec))"><canvas x-ref="canvas"></canvas></div>
 *
 * The page gives it a plain description; a `wire:key` made from the data's hash re-mounts it when the figures change.
 */
window.analyticsChart = (spec) => ({
    chart: null,

    async init() {
        const { mountChart } = await import('./analytics-charts');
        this.chart = mountChart(this.$refs.canvas, spec);
    },

    destroy() {
        this.chart?.destroy();
        this.chart = null;
    },
});
