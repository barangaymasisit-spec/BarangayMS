/* global Chart */

document.addEventListener('DOMContentLoaded', function () {
    const currentDate = document.getElementById('currentDate');
    const currentTime = document.getElementById('currentTime');
    const specialTotalElement = document.getElementById('specialTotal');
    const specialCategoryCanvas = document.getElementById('specialCategoryChart');
    const serviceTrendCanvas = document.getElementById('serviceTrendChart');

    function updateClock() {
        const now = new Date();
        const optionsDate = {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        };
        const optionsTime = {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        };

        if (currentDate) {
            currentDate.textContent = now.toLocaleDateString('en-US', optionsDate);
        }
        if (currentTime) {
            currentTime.textContent = now.toLocaleTimeString('en-US', optionsTime);
        }
    }

    updateClock();
    setInterval(updateClock, 1000);

    const specialCategoryData = Object.assign({
        seniorCitizen: 0,
        soloParent: 0,
        pwd: 0,
        indigenous: 0,
        pregnant: 0,
        lactating: 0,
        ofw: 0,
        fourPs: 0
    }, window.specialCategoryData || {});

    const total = Object.values(specialCategoryData).reduce((sum, value) => sum + value, 0);
    if (specialTotalElement) {
        specialTotalElement.innerText = total;
    }

    if (specialCategoryCanvas) {
        const ChartConstructor = window.Chart || (typeof Chart !== 'undefined' ? Chart : null);
        if (ChartConstructor) {
            const ctx = specialCategoryCanvas.getContext('2d');
            new ChartConstructor(ctx, {
                type: 'doughnut',
                data: {
                    labels: [
                        'Senior Citizen',
                        'Solo Parent',
                        'PWD',
                        'Indigenous',
                        'Pregnant',
                        'Lactating',
                        'OFW Family',
                        '4Ps'
                    ],
                    datasets: [{
                        data: [
                            specialCategoryData.seniorCitizen,
                            specialCategoryData.soloParent,
                            specialCategoryData.pwd,
                            specialCategoryData.indigenous,
                            specialCategoryData.pregnant,
                            specialCategoryData.lactating,
                            specialCategoryData.ofw,
                            specialCategoryData.fourPs
                        ],
                        backgroundColor: [
                            '#0B4A9E',
                            '#E53935',
                            '#43A047',
                            '#FB8C00',
                            '#8E24AA',
                            '#26C6DA',
                            '#FBC02D',
                            '#5E35B1'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    }

    if (serviceTrendCanvas && window.serviceTrendData) {
        const ChartConstructor = window.Chart || (typeof Chart !== 'undefined' ? Chart : null);
        if (ChartConstructor) {
            const trendCtx = serviceTrendCanvas.getContext('2d');
            const trendData = window.serviceTrendData || { labels: [], residents: [], appointments: [], complaints: [], certificates: [] };

            new ChartConstructor(trendCtx, {
                type: 'line',
                data: {
                    labels: trendData.labels,
                    datasets: [
                        {
                            label: 'Residents',
                            data: trendData.residents,
                            borderColor: '#0B4A9E',
                            backgroundColor: 'rgba(11, 74, 158, 0.12)',
                            borderWidth: 3,
                            tension: 0.35,
                            fill: false
                        },
                        {
                            label: 'Appointments',
                            data: trendData.appointments,
                            borderColor: '#E53935',
                            backgroundColor: 'rgba(229, 57, 53, 0.12)',
                            borderWidth: 3,
                            tension: 0.35,
                            fill: false
                        },
                        {
                            label: 'Complaints',
                            data: trendData.complaints,
                            borderColor: '#43A047',
                            backgroundColor: 'rgba(67, 160, 71, 0.12)',
                            borderWidth: 3,
                            tension: 0.35,
                            fill: false
                        },
                        {
                            label: 'Certificates',
                            data: trendData.certificates,
                            borderColor: '#FB8C00',
                            backgroundColor: 'rgba(251, 140, 0, 0.12)',
                            borderWidth: 3,
                            tension: 0.35,
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        }
    }
});
