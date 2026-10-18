// App.js
// CD Alerts Script
window.CDAlert = {
    show: function(message, type = 'default', position = 'bottom') {
        const alertEl = document.createElement('div');
        alertEl.classList.add('cd-alert');
        alertEl.classList.add(position === 'top' ? 'cd-alert-top' : 'cd-alert-bottom');
        
        if (type === 'error') alertEl.classList.add('cd-alert-error');
        if (type === 'success') alertEl.classList.add('cd-alert-success');
        
        alertEl.textContent = message;
        document.body.appendChild(alertEl);

        setTimeout(() => {
            alertEl.remove();
        }, 3300);
    }
};

//===== DOCS =======//
// Default bottom alert
// CDAlert.show('Item added to cart!');

// Success alert at the top
// CDAlert.show('Profile Updated Successfully', 'success', 'top');

// Error alert at the bottom
// CDAlert.show('Connection Failed', 'error', 'bottom');


// All Categories Toggle Script 
function toggleQL() {
    const e = document.getElementById("qlBox"),
          t = document.getElementById("qlBtn");

    e.classList.toggle("expanded");

    e.classList.contains("expanded")
        ? t.innerHTML = "Show Less &#8722;"
        : t.innerHTML = "View More Categories &#65291;";
}