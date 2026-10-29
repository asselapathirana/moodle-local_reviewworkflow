// Copyright (c) 2026 Assela Pathirana
// SPDX-License-Identifier: GPL-3.0-or-later
(() => {
    'use strict';
    const table = document.querySelector('#reviews');
    const rows = Array.from(table.tBodies[0].rows);
    const search = document.querySelector('#search');
    const status = document.querySelector('#status');
    const decision = document.querySelector('#decision');
    const filter = () => {
        const words = search.value.toLocaleLowerCase().trim().split(/\s+/).filter(Boolean);
        let count = 0;
        rows.forEach(row => {
            row.hidden = !(words.every(word => row.textContent.toLocaleLowerCase().includes(word)) &&
                (!status.value || row.dataset.state === status.value) &&
                (!decision.value || row.dataset.decision === decision.value));
            if (!row.hidden) count++;
        });
        document.querySelector('#count').textContent = `${count} of ${rows.length} results`;
        document.querySelector('#empty').hidden = count !== 0;
    };
    [search, status, decision].forEach(field => field.addEventListener('input', filter));
    document.querySelector('#reset').addEventListener('click', () => {
        search.value = ''; status.value = ''; decision.value = ''; filter(); search.focus();
    });
    const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
    table.querySelectorAll('th button').forEach(button => button.addEventListener('click', () => {
        const column = Number(button.dataset.col);
        const descending = button.parentElement.getAttribute('aria-sort') === 'ascending';
        rows.sort((a, b) => collator.compare(a.cells[column].dataset.sort ?? a.cells[column].textContent,
            b.cells[column].dataset.sort ?? b.cells[column].textContent) * (descending ? -1 : 1));
        table.querySelectorAll('th').forEach(th => th.setAttribute('aria-sort', 'none'));
        button.parentElement.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
        rows.forEach(row => table.tBodies[0].append(row));
    }));
    filter();
})();
