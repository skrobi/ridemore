/* ============================================================================
   MANAGE PANEL - JavaScript helpers
   Minimum JS - tylko UX improvements
   ============================================================================ */

// Auto-hide flash messages
document.addEventListener('DOMContentLoaded', function() {
    const flashes = document.querySelectorAll('.manage-flash');
    flashes.forEach(flash => {
        setTimeout(() => {
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-10px)';
            setTimeout(() => flash.remove(), 300);
        }, 5000);
    });
});

// Confirm before leaving page with unsaved changes
let formChanged = false;

document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('.manage-form, .medal-form');
    
    forms.forEach(form => {
        const inputs = form.querySelectorAll('input, textarea, select');
        
        inputs.forEach(input => {
            input.addEventListener('change', () => {
                formChanged = true;
            });
        });
        
        form.addEventListener('submit', () => {
            formChanged = false;
        });
    });
});

window.addEventListener('beforeunload', function(e) {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = '';
    }
});

// Helper functions
window.escapeHtml = function(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
};

// Global error handler
window.addEventListener('error', function(e) {
    console.error('JS Error:', e.message, e.filename, e.lineno);
});