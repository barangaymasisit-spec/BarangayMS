function updateClock() {
    const now = new Date();

    const optionsDate = {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric"
    };

    const optionsTime = {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: true
    };

    document.getElementById("currentDate").textContent =
        now.toLocaleDateString("en-US", optionsDate);

    document.getElementById("currentTime").textContent =
        now.toLocaleTimeString("en-US", optionsTime);
}

updateClock();

setInterval(updateClock, 1000); 