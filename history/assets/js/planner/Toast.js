/* ============================================================================
 TOAST - Lightweight notification system
 Usage: Toast.show('Trasa zapisana!', 'success')
        Toast.show('Błąd zapisu', 'error')
        Toast.show('Ładowanie...', 'info')
 ============================================================================ */

window.Toast = {
    _container: null,

    _getContainer() {
        if (!this._container) {
            this._container = document.createElement('div');
            this._container.style.cssText = `
                position: fixed;
                top: 16px;
                right: 16px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 8px;
                pointer-events: none;
            `;
            document.body.appendChild(this._container);
        }
        return this._container;
    },

    show(message, type = 'info', duration = 3000) {
        const colors = {
            success: { bg: '#10b981', icon: '✅' },
            error:   { bg: '#ef4444', icon: '❌' },
            info:    { bg: '#3b82f6', icon: 'ℹ️'  },
            warning: { bg: '#f59e0b', icon: '⚠️' }
        };

        const { bg, icon } = colors[type] || colors.info;

        const el = document.createElement('div');
        el.style.cssText = `
            background: ${bg};
            color: white;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            pointer-events: auto;
            opacity: 0;
            transform: translateX(16px);
            transition: opacity 0.2s, transform 0.2s;
            max-width: 320px;
        `;
        el.innerHTML = `<span>${icon}</span><span>${message}</span>`;

        this._getContainer().appendChild(el);

        requestAnimationFrame(() => {
            el.style.opacity = '1';
            el.style.transform = 'translateX(0)';
        });

        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transform = 'translateX(16px)';
            setTimeout(() => el.remove(), 200);
        }, duration);
    }
};

console.log('✅ Toast loaded');