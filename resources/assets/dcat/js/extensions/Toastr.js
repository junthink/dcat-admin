import DOMPurify from 'dompurify';
const clean = value => typeof value === 'string' ? DOMPurify.sanitize(value, {USE_PROFILES: {html: true}}) : value;

export default class Toastr {
    constructor(Dcat) {
        let _this = this;

        Dcat.success = _this.success;
        Dcat.error = _this.error;
        Dcat.info = _this.info;
        Dcat.warning = _this.warning;
    }

    success(message, title, options) {
        toastr.success(clean(message), clean(title), options);
    }

    error(message, title, options) {
        toastr.error(clean(message), clean(title), options);
    }

    info(message, title, options) {
        toastr.info(clean(message), clean(title), options);
    }

    warning(message, title, options) {
        toastr.warning(clean(message), clean(title), options);
    }
}
