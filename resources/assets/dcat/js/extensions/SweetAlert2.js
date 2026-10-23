import DOMPurify from 'dompurify';

import Swal from 'sweetalert2/dist/sweetalert2.esm.all.js'

let w = window;

export default class SweetAlert2 {
    constructor(Dcat) {
        let _this = this;

        Swal.success = _this.success.bind(_this);
        Swal.error = _this.error.bind(_this);
        Swal.info = _this.info.bind(_this);
        Swal.warning = _this.warning.bind(_this);
        Swal.confirm = _this.confirm.bind(_this);

        w.swal = w.Swal = _this.swal = Dcat.swal = Swal;

        Dcat.confirm = Swal.confirm;
    }

    success(title, message, options) {
        return this.fire(title, message, 'success', options)
    }

    error(title, message, options) {
        return this.fire(title, message, 'error', options)
    }

    info(title, message, options) {
        return this.fire(title, message, 'info', options)
    }

    warning(title, message, options) {
        return this.fire(title, message, 'warning', options)
    }

    confirm(title, message, success, fail, options) {
        let lang = Dcat.lang;

        options = $.extend({
            showCancelButton: true,
            showLoaderOnConfirm: true,
            confirmButtonText: lang['confirm'],
            cancelButtonText: lang['cancel'],
            customClass: {confirmButton: 'btn btn-primary', cancelButton: 'btn btn-white ml-1'},
            buttonsStyling: false,
        }, options);

        this.fire(title, message, 'question', options).then(function (result) {
            if (result.isConfirmed) {
                return success && success()
            }

            fail && fail()
        })
    }

    fire(title, message, type, options) {
        options = $.extend({
            title: title,
            icon: type,
            html: message,
        }, options);

        if (options.type && !options.icon) options.icon = options.type;
        delete options.type;
        options.customClass = options.customClass || {};
        for (const [oldName, name] of [['confirmButtonClass', 'confirmButton'], ['cancelButtonClass', 'cancelButton']]) {
            if (options[oldName]) options.customClass[name] = options[oldName];
            delete options[oldName];
        }
        if (typeof options.title === 'string') options.title = DOMPurify.sanitize(options.title, {USE_PROFILES: {html: true}});
        if (typeof options.html === 'string') options.html = DOMPurify.sanitize(options.html, {USE_PROFILES: {html: true}});
        return this.swal.fire(options);
    }
}
