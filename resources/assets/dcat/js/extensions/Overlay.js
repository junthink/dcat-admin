export default class Overlay {
    constructor() {
        $(document).off('.dcat-overlays')
            .on('inserted.bs.popover.dcat-overlays', function (event) {
                const trigger = $(event.target), popover = trigger.data('bs.popover');
                if (!popover) return;

                // Keep explicit custom containers; attach default popovers to their dialog.
                const container = popover.config.container;
                if (container && container !== 'body' && container !== document.body) return;

                const owner = trigger.closest('.modal, .layui-layer, .slider-panel');
                if (owner.length) owner.append(popover.getTipElement());
            })
            .on('focusin.dcat-overlays', function (event) {
                // Bootstrap's modal focus trap otherwise rejects TinyMCE's body-level dialogs.
                if ($(event.target).closest('.tox-tinymce-aux').length) {
                    event.stopImmediatePropagation();
                }
            });
    }
}
