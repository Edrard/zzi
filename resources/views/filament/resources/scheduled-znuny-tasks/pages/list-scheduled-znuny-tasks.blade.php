<x-filament-panels::page>
<style>
@media (max-width: 900px) {
    .scheduled-task-filter-row {
        grid-template-columns: 1fr !important;
    }
}
/* Reduce table cell padding for density */
.fi-ta-table td {
    padding-top: 0.25rem !important;
    padding-bottom: 0.25rem !important;
}
/* Reduce inline input height for density */
.fi-ta-table .fi-input-wrapper {
    min-height: 2rem !important;
}
.fi-ta-table select, .fi-ta-table input {
    padding-top: 0.25rem !important;
    padding-bottom: 0.25rem !important;
    min-height: 2rem !important;
    height: 2rem !important;
}
/* Disabled row muted styling (light & dark mode safe) */
#scheduled-tasks-page-wrapper tr.scheduled-task-disabled-row > td {
    background-color: #eef2f7 !important;
    color: #475569;
}
#scheduled-tasks-page-wrapper tr.scheduled-task-disabled-row > td:first-child {
    box-shadow: inset 4px 0 0 #94a3b8;
}
#scheduled-tasks-page-wrapper tr.scheduled-task-disabled-row:hover > td {
    background-color: #e2e8f0 !important;
}

/* Distinct hover for enabled rows to avoid looking disabled */
#scheduled-tasks-page-wrapper tr:not(.scheduled-task-disabled-row):hover > td {
    background-color: #eff6ff !important;
}

/* Dark mode */
.dark #scheduled-tasks-page-wrapper tr.scheduled-task-disabled-row > td {
    background-color: #27272a !important;
    color: #d4d4d8;
}
.dark #scheduled-tasks-page-wrapper tr.scheduled-task-disabled-row > td:first-child {
    box-shadow: inset 4px 0 0 #71717a;
}
.dark #scheduled-tasks-page-wrapper tr.scheduled-task-disabled-row:hover > td {
    background-color: #3f3f46 !important;
}
.dark #scheduled-tasks-page-wrapper tr:not(.scheduled-task-disabled-row):hover > td {
    background-color: #262626 !important;
}
</style>
    <div id="scheduled-tasks-page-wrapper" data-scheduled-tasks-page style="margin-top: 0 !important; display: flex; flex-direction: column; gap: 0.5rem;">
        <div class="p-4 bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 rounded-xl">
            <div
                class="scheduled-task-filter-row"
                style="display:grid;grid-template-columns:minmax(260px,1.2fr) minmax(190px,1fr) minmax(190px,1fr) minmax(160px,.7fr);gap:12px;align-items:end;"
            >
                <x-filament::input.wrapper icon="heroicon-m-magnifying-glass">
                    <x-filament::input type="search" wire:model.live.debounce.500ms="taskSearch" placeholder="{{ __('scheduled_znuny_tasks.filters.search') }}" />
                </x-filament::input.wrapper>

                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="queueFilter">
                        <option value="">{{ __('scheduled_znuny_tasks.filters.all_queues') }}</option>
                        @foreach($this->getQueueOptions() as $queue)
                            <option value="{{ $queue }}">{{ $queue }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="ownerFilter">
                        <option value="">{{ __('scheduled_znuny_tasks.filters.all_owners') }}</option>
                        @foreach($this->getOwnerOptions() as $id => $owner)
                            <option value="{{ $id }}">{{ $owner }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="activeFilter">
                        <option value="all">{{ __('scheduled_znuny_tasks.filters.all_statuses') }}</option>
                        <option value="1">{{ __('scheduled_znuny_tasks.filters.active') }}</option>
                        <option value="0">{{ __('scheduled_znuny_tasks.filters.inactive') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </div>

        <div>
            {{ $this->table }}
        </div>
    </div>

<style>
/* Tighten vertical spacing between the widget (status panel) and this content */
.fi-page-content:has([data-scheduled-tasks-page]) {
    gap: 0.5rem !important;
}
.fi-page-header-widgets:has(+ .fi-page-content [data-scheduled-tasks-page]) {
    margin-bottom: 0.5rem !important;
}
.fi-ta-table th {
    cursor: pointer;
    user-select: none;
    transition: background-color 0.2s;
}
.fi-ta-table th:hover {
    background-color: rgba(128, 128, 128, 0.05);
}
</style>

<script>
(function() {
    let currentCol = 0;
    let currentAsc = false;
    let boundDelegation = false;

    function initScheduledTasksClientSort() {
        const wrapper = document.getElementById('scheduled-tasks-page-wrapper');
        if (!wrapper) return;

        if (!boundDelegation) {
            wrapper.addEventListener('click', function(e) {
                // Do not sort if user is interacting with an input/select
                if (e.target.closest('input, select, button, a, label')) return;

                const th = e.target.closest('th');
                if (!th) return;

                const thead = th.closest('thead');
                if (!thead) return;

                const table = thead.closest('table');
                if (!table || !table.classList.contains('fi-ta-table')) return;

                // Find index of clicked TH
                const ths = Array.from(thead.querySelectorAll('th'));
                const index = ths.indexOf(th);

                if (index < 0 || index > 7) return; // Only visible columns 0-7

                if (currentCol === index) {
                    currentAsc = !currentAsc;
                } else {
                    currentCol = index;
                    currentAsc = true;
                }

                updateIndicators(ths);
                applyScheduledTasksSort(table);
            });
            boundDelegation = true;
        }

        // Apply sort if already active (e.g. after Livewire morph)
        if (currentCol !== null) {
            const table = wrapper.querySelector('.fi-ta-table');
            if (table) {
                const ths = Array.from(table.querySelectorAll('thead th'));
                updateIndicators(ths);
                applyScheduledTasksSort(table);
            }
        }
    }

    function updateIndicators(headers) {
        headers.forEach((th, index) => {
            const existing = th.querySelector('.stcs-indicator');
            if (existing) existing.remove();

            if (index === currentCol) {
                const span = document.createElement('span');
                span.className = 'stcs-indicator';
                span.style.marginLeft = '4px';
                span.style.fontSize = '0.8em';
                span.innerHTML = currentAsc ? '&#9650;' : '&#9660;';

                const label = th.querySelector('.fi-ta-header-cell-label') || th.firstElementChild || th;
                label.appendChild(span);
            }
        });
    }

    function extractValue(td) {
        if (!td) return { empty: true, value: '' };

        let text = '';
        const select = td.querySelector('select');
        if (select) {
            const option = select.options[select.selectedIndex];
            text = option ? option.text.trim() : '';
        } else {
            const attr = td.getAttribute('data-scheduled-sort-value');
            if (attr !== null) {
                text = attr.trim();
            } else {
                text = td.innerText.trim();
            }
        }

        if (text === 'Not resolved' || text === 'Not selected' || text === 'Not calculated' || text === '{{ __("scheduled_znuny_tasks.placeholders.not_resolved") }}' || text === '{{ __("scheduled_znuny_tasks.placeholders.not_selected") }}' || text === '{{ __("scheduled_znuny_tasks.placeholders.not_calculated") }}' || text === '—') {
            text = '';
        }

        return { empty: text === '', value: text };
    }

    function applyScheduledTasksSort(table) {
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr'));
        if (rows.length === 0) return;

        rows.sort((a, b) => {
            const tdsA = a.querySelectorAll('td');
            const tdsB = b.querySelectorAll('td');

            if (!tdsA[currentCol] || !tdsB[currentCol]) return 0;

            let valA = extractValue(tdsA[currentCol]);
            let valB = extractValue(tdsB[currentCol]);

            if (valA.empty && !valB.empty) return currentAsc ? -1 : 1;
            if (!valA.empty && valB.empty) return currentAsc ? 1 : -1;
            if (valA.empty && valB.empty) return 0;

            const isNumeric = !isNaN(Number(valA.value)) && !isNaN(Number(valB.value));

            if (isNumeric) {
                const numA = Number(valA.value);
                const numB = Number(valB.value);
                return currentAsc ? numA - numB : numB - numA;
            }

            if (typeof valA.value === 'string' && typeof valB.value === 'string') {
                return currentAsc ? valA.value.localeCompare(valB.value) : valB.value.localeCompare(valA.value);
            }

            return currentAsc ? (valA.value > valB.value ? 1 : -1) : (valB.value > valA.value ? 1 : -1);
        });

        rows.forEach(row => tbody.appendChild(row));
    }

    document.addEventListener('livewire:navigated', initScheduledTasksClientSort);
    document.addEventListener('DOMContentLoaded', initScheduledTasksClientSort);

    if (window.Livewire && !window.stcs_hasBoundMorphHooks) {
        window.stcs_hasBoundMorphHooks = true;
        Livewire.hook('morph.updated', ({ el, component }) => {
            if (document.getElementById('scheduled-tasks-page-wrapper')) {
                // Defer to let morph complete
                requestAnimationFrame(() => initScheduledTasksClientSort());
            }
        });
    }

    window.scheduledTasksClientSort = initScheduledTasksClientSort;
})();
</script>
</x-filament-panels::page>
