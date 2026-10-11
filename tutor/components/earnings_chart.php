<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('monthlyEarningsChart');
    if (!ctx) return;

    const currencySymbol = '<?= addslashes($currency_symbol) ?>';
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Monthly Earnings',
                data: <?= json_encode($chartData) ?>,
                backgroundColor: 'rgba(99, 102, 241, 0.6)',
                borderColor: 'rgba(99, 102, 241, 1)',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255, 255, 255, 0.1)' },
                    ticks: { color: '#9ca3af', callback: (value) => currencySymbol + value }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#9ca3af' }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (context) => currencySymbol + (context.parsed.y ?? 0).toFixed(2)
                    }
                }
            }
        }
    });
});
</script>