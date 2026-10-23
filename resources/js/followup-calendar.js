import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';

// Overdue, planned and done, in the colours of the legend above the calendar.
const COLOURS = {
    overdue: { backgroundColor: '#ffe4e6', borderColor: '#f43f5e', textColor: '#9f1239' },
    planned: { backgroundColor: '#e0f2fe', borderColor: '#0ea5e9', textColor: '#075985' },
    done: { backgroundColor: '#d1fae5', borderColor: '#10b981', textColor: '#065f46' },
};

// "2026-10-12T09:30:00Z" -> "2026-10-12T09:30:00": the server works in its own wall-clock time, not in UTC.
const wallClock = (iso) => iso.slice(0, 19);

/**
 * Mount the follow-up calendar on `el`.
 *
 * Every time shown is the application's wall-clock time, whatever the browser's time zone: the calendar runs in "UTC"
 * and is fed times without an offset, so nothing is ever shifted. `offsetMinutes` (how far the application's clock is
 * ahead of UTC) is used only to put "today" and the now-line in the right place.
 *
 * `wire` is the Livewire component: `wire.events(start, end)` returns the entries of a window, and
 * `wire.move(id, start)` asks the server to move one. The server decides; a refusal puts the entry back.
 */
export function mountFollowupCalendar(el, wire, { offsetMinutes = 0 } = {}, notify = () => {}) {
    const calendar = new Calendar(el, {
        plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
        timeZone: 'UTC',
        now: () => new Date(Date.now() + offsetMinutes * 60000),
        initialView: window.innerWidth < 768 ? 'listWeek' : 'timeGridWeek',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth,listWeek' },
        buttonText: { today: 'Today', day: 'Day', week: 'Week', month: 'Month', list: 'List' },
        // dates the way the rest of the application writes them: day before month, 24-hour clock
        locale: 'en-IN',
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        listDayFormat: { weekday: 'long', day: 'numeric', month: 'long' },
        listDaySideFormat: false,
        // each view names its days its own way: a month's columns are weekdays, a week's are dates
        views: {
            timeGridDay: { titleFormat: { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }, dayHeaderFormat: { weekday: 'long' } },
            timeGridWeek: { titleFormat: { day: 'numeric', month: 'short', year: 'numeric' }, dayHeaderFormat: { weekday: 'short', day: 'numeric', month: 'short' } },
            dayGridMonth: { titleFormat: { month: 'long', year: 'numeric' }, dayHeaderFormat: { weekday: 'short' } },
            listWeek: { titleFormat: { day: 'numeric', month: 'short', year: 'numeric' } },
        },
        firstDay: 1,
        height: 720,
        nowIndicator: true,
        allDaySlot: false,
        scrollTime: '08:00:00',
        slotDuration: '00:30:00',
        // a follow-up is a moment, not a span: show when it is, and keep two at the same time side by side
        displayEventEnd: false,
        slotEventOverlap: false,
        dayMaxEvents: 4,
        navLinks: true,
        eventDurationEditable: false,
        noEventsContent: 'No follow-ups in this period.',

        events(info, success, failure) {
            wire.events(wallClock(info.startStr), wallClock(info.endStr)).then(success).catch(failure);
        },

        eventDataTransform(event) {
            return { ...event, ...(COLOURS[event.extendedProps?.state] ?? COLOURS.planned) };
        },

        eventDidMount(info) {
            info.el.title = [info.event.title, info.event.extendedProps.summary].filter(Boolean).join('\n');
        },

        async eventDrop(info) {
            let result;

            try {
                result = await wire.move(Number(info.event.id), wallClock(info.event.startStr));
            } catch (e) {
                result = { ok: false, message: 'The follow-up could not be moved. Please try again.' };
            }

            if (!result?.ok) {
                info.revert();
            }

            notify(result?.message ?? '', !result?.ok);
            calendar.refetchEvents();
        },
    });

    calendar.render();

    return calendar;
}
