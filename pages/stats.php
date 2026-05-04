<?php
$db = getDB();
$isAdm = isAdmin();
$technicians  = $isAdm
    ? $db->query("SELECT id, name FROM technicians WHERE active=1 ORDER BY name")->fetchAll()
    : [];
$cleaningTypes = $db->query("SELECT id, label FROM cleaning_types WHERE active=1 ORDER BY sort_order, label")->fetchAll();
$currentYear   = date('Y');
?>

<div class="stats-page">

    <!-- Filters -->
    <div class="section-card stats-filters">
        <div class="filter-group">
            <label class="form-label">Année</label>
            <select id="sYear" class="form-select" onchange="loadStats()">
                <option value="<?= $currentYear ?>"><?= $currentYear ?></option>
            </select>
        </div>
        <?php if ($isAdm): ?>
        <div class="filter-group">
            <label class="form-label">Technicien</label>
            <select id="sTech" class="form-select" onchange="loadStats()">
                <option value="0">Tous</option>
                <?php foreach ($technicians as $t): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="filter-group">
            <label class="form-label">Type de service</label>
            <select id="sType" class="form-select" onchange="loadStats()">
                <option value="0">Tous</option>
                <?php foreach ($cleaningTypes as $ct): ?>
                <option value="<?= $ct['id'] ?>"><?= htmlspecialchars($ct['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- KPI cards -->
    <div class="stats-kpis">
        <div class="kpi-card">
            <span class="kpi-label">Chiffre d'affaires</span>
            <span class="kpi-value" id="kpiCa">—</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Prestations</span>
            <span class="kpi-value" id="kpiNb">—</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Panier moyen</span>
            <span class="kpi-value" id="kpiAvg">—</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Meilleur mois</span>
            <span class="kpi-value" id="kpiBest">—</span>
            <span class="kpi-sub" id="kpiBestCa"></span>
        </div>
    </div>

    <!-- Monthly CA chart -->
    <div class="section-card">
        <h3 class="card-section-title">Évolution mensuelle du CA</h3>
        <div class="chart-wrapper-lg">
            <canvas id="chartMonthly"></canvas>
        </div>
    </div>

    <!-- Payment + Lieu charts -->
    <div class="stats-charts-row">
        <div class="section-card">
            <h3 class="card-section-title">Répartition par mode de paiement</h3>
            <div class="chart-wrapper-sm">
                <canvas id="chartPayment"></canvas>
            </div>
        </div>
        <div class="section-card">
            <h3 class="card-section-title">Lieu d'intervention</h3>
            <div class="chart-wrapper-sm">
                <canvas id="chartLieu"></canvas>
            </div>
        </div>
    </div>

    <!-- Table by service type -->
    <div class="section-card">
        <h3 class="card-section-title">Détail par type de service</h3>
        <div class="stats-table-wrap">
            <table class="stats-table">
                <thead>
                    <tr>
                        <th>Type de service</th>
                        <th class="th-num">Nb prestations</th>
                        <th class="th-num">CA total</th>
                        <th class="th-num">CA moyen</th>
                    </tr>
                </thead>
                <tbody id="tableByType">
                    <tr><td colspan="4" class="stats-empty">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    'use strict';

    // Chart instances
    let chartMonthly = null, chartPayment = null, chartLieu = null;
    let yearsPopulated = false;

    const MONTHS = ['Jan','Fév','Mars','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
    const PAY_LABELS = { cash: 'Cash', virement: 'Virement', qrcode: 'QR Code', facture: 'Sur Facture' };
    const PAY_COLORS = { cash: '#22c55e', virement: '#596FF3', qrcode: '#8b5cf6', facture: '#f97316' };

    Chart.defaults.font.family = 'Poppins';
    Chart.defaults.color = '#6b7280';

    function fmt(n) {
        return Number(n).toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    function val(id) {
        const el = document.getElementById(id);
        return el ? el.value : '0';
    }

    // ── Main fetch ────────────────────────────────────────────────
    async function loadStats() {
        const year = val('sYear');
        const tech = val('sTech');
        const type = val('sType');

        try {
            const res = await fetch(`api/stats.php?year=${year}&tech=${tech}&type=${type}`);
            const d   = await res.json();

            populateYears(d.years, year);
            renderKPI(d);
            renderMonthly(d);
            renderPayment(d);
            renderLieu(d);
            renderTable(d);
        } catch (e) {
            console.error('Stats fetch error', e);
        }
    }

    function populateYears(years, current) {
        if (yearsPopulated) return;
        yearsPopulated = true;
        const sel = document.getElementById('sYear');
        sel.innerHTML = '';
        years.forEach(y => {
            const opt = document.createElement('option');
            opt.value = y;
            opt.textContent = y;
            if (String(y) === String(current)) opt.selected = true;
            sel.appendChild(opt);
        });
    }

    // ── KPI ───────────────────────────────────────────────────────
    function renderKPI(d) {
        document.getElementById('kpiCa').textContent  = fmt(d.kpi.total_ca);
        document.getElementById('kpiNb').textContent  = d.kpi.nb.toLocaleString('fr-BE');
        document.getElementById('kpiAvg').textContent = fmt(d.kpi.avg_ca);
        if (d.kpi.best_month) {
            document.getElementById('kpiBest').textContent   = MONTHS[d.kpi.best_month - 1];
            document.getElementById('kpiBestCa').textContent = fmt(d.kpi.best_month_ca);
        } else {
            document.getElementById('kpiBest').textContent   = '—';
            document.getElementById('kpiBestCa').textContent = '';
        }
    }

    // ── Monthly bar chart ─────────────────────────────────────────
    function renderMonthly(d) {
        if (chartMonthly) chartMonthly.destroy();

        const ctx = document.getElementById('chartMonthly').getContext('2d');
        const stacked = d.type_monthly && d.type_monthly.length > 1;

        const datasets = stacked
            ? d.type_monthly.map(t => ({
                label: t.label,
                data: t.months,
                backgroundColor: t.color + 'bb',
                borderColor: t.color,
                borderWidth: 1,
                borderRadius: 4,
                borderSkipped: false,
            }))
            : [{
                label: 'CA',
                data: d.monthly.map(m => m.ca),
                backgroundColor: '#596FF3bb',
                borderColor: '#596FF3',
                borderWidth: 1,
                borderRadius: 4,
                borderSkipped: false,
            }];

        chartMonthly = new Chart(ctx, {
            type: 'bar',
            data: { labels: MONTHS, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 350 },
                plugins: {
                    legend: {
                        display: stacked,
                        position: 'top',
                        labels: { boxWidth: 12, padding: 16, font: { size: 12 } },
                    },
                    tooltip: {
                        callbacks: {
                            label:      ctx  => ` ${ctx.dataset.label}: ${fmt(ctx.parsed.y)}`,
                            afterTitle: items => {
                                const m = d.monthly[items[0].dataIndex];
                                return `${m.nb} prestation${m.nb > 1 ? 's' : ''}`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: { font: { size: 11 } },
                    },
                    y: {
                        stacked: true,
                        border: { dash: [4, 4] },
                        grid: { color: '#e5e7eb' },
                        ticks: {
                            font: { size: 11 },
                            callback: v => v === 0 ? '0 €' : (v >= 1000 ? (v / 1000).toFixed(0) + 'k €' : v + ' €'),
                        },
                    },
                },
            },
        });
    }

    // ── Payment doughnut ──────────────────────────────────────────
    function renderPayment(d) {
        if (chartPayment) chartPayment.destroy();

        if (!d.by_payment.length) return;

        const ctx    = document.getElementById('chartPayment').getContext('2d');
        const labels = d.by_payment.map(p => PAY_LABELS[p.paiement] || p.paiement);
        const data   = d.by_payment.map(p => p.ca);
        const colors = d.by_payment.map(p => PAY_COLORS[p.paiement] || '#94a3b8');

        chartPayment = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: colors.map(c => c + 'cc'),
                    borderColor:     colors,
                    borderWidth: 2,
                    hoverOffset: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 350 },
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 14, font: { size: 11 } },
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                const p = d.by_payment[ctx.dataIndex];
                                const total = d.by_payment.reduce((s, x) => s + x.ca, 0);
                                const pct = total ? Math.round(ctx.parsed / total * 100) : 0;
                                return ` ${fmt(ctx.parsed)} (${pct}%)`;
                            },
                            afterLabel: ctx => ` ${d.by_payment[ctx.dataIndex].nb} prestation${d.by_payment[ctx.dataIndex].nb > 1 ? 's' : ''}`,
                        },
                    },
                },
            },
        });
    }

    // ── Lieu doughnut ─────────────────────────────────────────────
    function renderLieu(d) {
        if (chartLieu) chartLieu.destroy();

        const dom = d.by_lieu.domicile;
        const atl = d.by_lieu.atelier;
        if (!dom.nb && !atl.nb) return;

        const ctx = document.getElementById('chartLieu').getContext('2d');

        chartLieu = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Domicile', 'Atelier'],
                datasets: [{
                    data: [dom.ca, atl.ca],
                    backgroundColor: ['#596FF3bb', '#22c55ebb'],
                    borderColor:     ['#596FF3',   '#22c55e'],
                    borderWidth: 2,
                    hoverOffset: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 350 },
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 14, font: { size: 11 } },
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                const total = dom.ca + atl.ca;
                                const pct = total ? Math.round(ctx.parsed / total * 100) : 0;
                                return ` ${fmt(ctx.parsed)} (${pct}%)`;
                            },
                            afterLabel: ctx => {
                                const nb = ctx.dataIndex === 0 ? dom.nb : atl.nb;
                                return ` ${nb} prestation${nb > 1 ? 's' : ''}`;
                            },
                        },
                    },
                },
            },
        });
    }

    // ── Table by type ─────────────────────────────────────────────
    function renderTable(d) {
        const tbody = document.getElementById('tableByType');

        if (!d.by_type.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="stats-empty">Aucune donnée pour cette sélection</td></tr>';
            return;
        }

        const rows = d.by_type.map(t => `
            <tr>
                <td class="td-label">${t.label}</td>
                <td class="td-num">${t.nb}</td>
                <td class="td-num">${fmt(t.ca)}</td>
                <td class="td-num">${fmt(t.avg_ca)}</td>
            </tr>
        `).join('');

        const totalNb  = d.by_type.reduce((s, t) => s + t.nb, 0);
        const totalCa  = d.by_type.reduce((s, t) => s + t.ca, 0);
        const totalAvg = totalNb ? totalCa / totalNb : 0;

        tbody.innerHTML = rows + `
            <tr class="stats-table-total">
                <td class="td-label">Total</td>
                <td class="td-num">${totalNb}</td>
                <td class="td-num">${fmt(totalCa)}</td>
                <td class="td-num">${fmt(totalAvg)}</td>
            </tr>
        `;
    }

    // Boot
    document.addEventListener('DOMContentLoaded', loadStats);

    // Expose for inline onchange handlers
    window.loadStats = loadStats;
})();
</script>
