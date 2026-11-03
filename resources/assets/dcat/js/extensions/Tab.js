let instance = 0;

export default class Tab {
    constructor(root) {
        this.$root = $(root);
        this.$root.data('dcat-tab-instance', this);
        this.namespace = '.dcatTab' + (++instance);
        this.$nav = this.$root.children('.dcat-tabs-nav');
        this.links = this.$nav.children('.nav-item').children('a.nav-link')
            .not('[data-toggle="dropdown"], .disabled, [aria-disabled="true"]').toArray();
        this.$toggle = this.$nav.find('[data-tab-search-toggle]');
        this.$panel = this.$nav.find('[data-tab-search-panel]');
        this.$input = this.$panel.find('[data-tab-search-input]');
        this.$results = this.$panel.find('[data-tab-search-results]');
        this.popper = null;

        this.$nav.on('shown.bs.tab' + this.namespace, 'a[data-toggle="tab"]', event => {
            if (!this.links.includes(event.target)) return;
            if (event.target.hash && location.hash !== event.target.hash) {
                history.pushState(null, null, event.target.hash);
            }
            this.filter();
        });
        const selected = this.links.find(link => link.getAttribute('data-toggle') === 'tab' && link.getAttribute('href') === location.hash);
        if (selected) $(selected).tab('show');

        if (this.$toggle.length) this.search();
        $(document).one('pjax:beforeReplace' + this.namespace, () => this.destroy());
    }

    search() {
        this.items = this.links.map(link => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dropdown-item dcat-tab-search-result';
            button.textContent = link.textContent.trim();
            button.addEventListener('click', () => {
                this.close();
                link.focus();
                link.click();
            });
            this.$results.append(button);
            return {link, button, text: (link.textContent + ' ' + (link.getAttribute('data-tab-search-text') || '')).toLocaleLowerCase()};
        });
        this.$toggle.on('click' + this.namespace, () => this.$panel.prop('hidden') ? this.open() : this.close(true));
        this.$input.on('input' + this.namespace, () => this.filter());
        this.$panel.on('keydown' + this.namespace, event => {
            if (event.originalEvent?.isComposing || event.which === 229) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.close(true);
                return;
            }
            const buttons = this.items.filter(item => !item.button.hidden).map(item => item.button);
            if (event.key === 'Enter' && event.target === this.$input[0]) {
                event.preventDefault();
                if (buttons.length) buttons[0].click();
                return;
            }
            if (!buttons.length) return;
            const index = buttons.indexOf(event.target);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const next = index < 0 ? (event.key === 'ArrowDown' ? 0 : buttons.length - 1)
                    : (index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
                buttons[next].focus();
            }
        });
        this.$panel.on('focusout' + this.namespace, event => {
            if (event.relatedTarget && !this.$nav.find('.dcat-tab-search')[0].contains(event.relatedTarget)) this.close();
        });
        $(document).on('click' + this.namespace, event => {
            if (!this.$nav.find('.dcat-tab-search')[0].contains(event.target)) this.close();
        });
        this.$toggle.prop('disabled', false);
    }

    filter() {
        if (!this.items) return;
        const words = this.$input.val().trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
        let matches = 0;
        this.items.forEach(({link, button, text}) => {
            button.hidden = !words.every(word => text.includes(word));
            button.classList.toggle('active', link.classList.contains('active'));
            if (!button.hidden) matches++;
        });
        this.$panel.find('[data-tab-search-empty]').prop('hidden', matches !== 0);
        this.popper?.scheduleUpdate();
    }

    open() {
        this.$input.val('');
        this.filter();
        this.$panel.prop('hidden', false);
        this.$toggle.attr('aria-expanded', 'true');
        this.popper = new window.Popper(this.$toggle[0], this.$panel[0], {
            placement: 'bottom-end',
            positionFixed: true,
            modifiers: {preventOverflow: {boundariesElement: 'viewport'}, flip: {boundariesElement: 'viewport'}}
        });
        this.$input.trigger('focus');
    }

    close(focus = false) {
        this.popper?.destroy();
        this.popper = null;
        this.$panel.prop('hidden', true);
        this.$toggle.attr('aria-expanded', 'false');
        if (focus) this.$toggle.trigger('focus');
    }

    destroy() {
        this.close();
        this.$nav.off(this.namespace);
        this.$panel.off(this.namespace);
        this.$input.off(this.namespace);
        this.$toggle.off(this.namespace);
        $(document).off(this.namespace);
        this.$root.removeData('dcat-tab-instance');
    }
}
