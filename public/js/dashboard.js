/**
 * Dashboard-specific JavaScript
 * Chart initialization and updates for dashboard page
 */

// Helper function to get CSS variable value
function getCSSVariable(variable) {
    return getComputedStyle(document.documentElement).getPropertyValue(variable).trim();
}

// Chart instances
let levelChart, fulfillmentChart, earningsChart, visitorChart;

// Initialize all charts
document.addEventListener('DOMContentLoaded', function() {
    initializeCharts();
    
    // Listen for theme changes
    window.addEventListener('themeChanged', function() {
        setTimeout(updateChartColors, 100);
    });
});

function initializeCharts() {
    // Level Chart (Bar Chart)
    const levelCtx = document.getElementById('levelChart');
    if (levelCtx) {
        levelChart = new Chart(levelCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [
                    {
                        label: 'Volume',
                        data: [65, 59, 80, 81, 56, 55],
                        backgroundColor: '#4ecdc4',
                        borderRadius: 4
                    },
                    {
                        label: 'Service',
                        data: [28, 48, 40, 19, 86, 27],
                        backgroundColor: getCSSVariable('--bg-tertiary'),
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        labels: {
                            color: getCSSVariable('--text-primary'),
                            font: { size: 12 }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: getCSSVariable('--text-secondary') },
                        grid: { color: getCSSVariable('--chart-grid') }
                    },
                    y: {
                        ticks: { color: getCSSVariable('--text-secondary') },
                        grid: { color: getCSSVariable('--chart-grid') }
                    }
                }
            }
        });
    }

    // Customer Fulfillment Chart (Area Chart)
    const fulfillmentCtx = document.getElementById('fulfillmentChart');
    if (fulfillmentCtx) {
        fulfillmentChart = new Chart(fulfillmentCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                datasets: [
                    {
                        label: 'This Month',
                        data: [3200, 3500, 4200, 4785],
                        borderColor: '#4ecdc4',
                        backgroundColor: 'rgba(78, 205, 196, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Last Month',
                        data: [2800, 3200, 3800, 4029],
                        borderColor: '#e91e63',
                        backgroundColor: 'rgba(233, 30, 99, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        labels: {
                            color: getCSSVariable('--text-primary'),
                            font: { size: 12 }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: getCSSVariable('--text-secondary') },
                        grid: { color: getCSSVariable('--chart-grid') }
                    },
                    y: {
                        ticks: { color: getCSSVariable('--text-secondary') },
                        grid: { color: getCSSVariable('--chart-grid') }
                    }
                }
            }
        });
    }

    // Earnings Chart (Donut Chart)
    const earningsCtx = document.getElementById('earningsChart');
    if (earningsCtx) {
        earningsChart = new Chart(earningsCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [80, 20],
                    backgroundColor: ['#4ecdc4', getCSSVariable('--bg-tertiary')],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                }
            },
            plugins: [{
                id: 'centerText',
                beforeDraw: function(chart) {
                    const ctx = chart.ctx;
                    const centerX = chart.chartArea.left + (chart.chartArea.right - chart.chartArea.left) / 2;
                    const centerY = chart.chartArea.top + (chart.chartArea.bottom - chart.chartArea.top) / 2;
                    ctx.save();
                    ctx.font = 'bold 32px sans-serif';
                    ctx.fillStyle = getCSSVariable('--text-primary');
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText('80%', centerX, centerY);
                    ctx.restore();
                }
            }]
        });
    }

    // Visitor Insights Chart (Area Chart)
    const visitorCtx = document.getElementById('visitorChart');
    if (visitorCtx) {
        visitorChart = new Chart(visitorCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'New Visitors',
                    data: [120, 190, 300, 250, 280, 350, 400, 380, 420, 450, 480, 500],
                    borderColor: '#4ecdc4',
                    backgroundColor: 'rgba(78, 205, 196, 0.2)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        ticks: { color: getCSSVariable('--text-secondary'), font: { size: 10 } },
                        grid: { color: getCSSVariable('--chart-grid') }
                    },
                    y: {
                        ticks: { color: getCSSVariable('--text-secondary') },
                        grid: { color: getCSSVariable('--chart-grid') },
                        max: 500
                    }
                }
            }
        });
    }
}

// Function to update chart colors when theme changes
function updateChartColors() {
    const textColor = getCSSVariable('--text-primary');
    const secondaryColor = getCSSVariable('--text-secondary');
    const gridColor = getCSSVariable('--chart-grid');
    const tertiaryBg = getCSSVariable('--bg-tertiary');

    // Update level chart
    if (levelChart) {
        levelChart.options.plugins.legend.labels.color = textColor;
        levelChart.data.datasets[1].backgroundColor = tertiaryBg;
        levelChart.options.scales.x.ticks.color = secondaryColor;
        levelChart.options.scales.x.grid.color = gridColor;
        levelChart.options.scales.y.ticks.color = secondaryColor;
        levelChart.options.scales.y.grid.color = gridColor;
        levelChart.update();
    }

    // Update fulfillment chart
    if (fulfillmentChart) {
        fulfillmentChart.options.plugins.legend.labels.color = textColor;
        fulfillmentChart.options.scales.x.ticks.color = secondaryColor;
        fulfillmentChart.options.scales.x.grid.color = gridColor;
        fulfillmentChart.options.scales.y.ticks.color = secondaryColor;
        fulfillmentChart.options.scales.y.grid.color = gridColor;
        fulfillmentChart.update();
    }

    // Update earnings chart
    if (earningsChart) {
        earningsChart.data.datasets[0].backgroundColor[1] = tertiaryBg;
        earningsChart.update();
    }

    // Update visitor chart
    if (visitorChart) {
        visitorChart.options.scales.x.ticks.color = secondaryColor;
        visitorChart.options.scales.x.grid.color = gridColor;
        visitorChart.options.scales.y.ticks.color = secondaryColor;
        visitorChart.options.scales.y.grid.color = gridColor;
        visitorChart.update();
    }
}
