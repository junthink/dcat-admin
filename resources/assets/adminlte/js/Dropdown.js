/**
 * --------------------------------------------
 * AdminLTE Dropdown.js
 * License MIT
 * --------------------------------------------
 */

const Dropdown = (($) => {
  /**
   * Constants
   * ====================================================
   */

  const NAME               = 'Dropdown'
  const DATA_KEY           = 'lte.dropdown'
  const EVENT_KEY          = `.${DATA_KEY}`
  const JQUERY_NO_CONFLICT = $.fn[NAME]

  const Selector = {
    NAVBAR: '.navbar',
    DROPDOWN_MENU: '.dropdown-menu',
    DROPDOWN_MENU_ACTIVE: '.dropdown-menu.show',
    DROPDOWN_TOGGLE: '[data-toggle="dropdown"]',
  }

  const ClassName = {
    DROPDOWN_HOVER: 'dropdown-hover',
    DROPDOWN_RIGHT: 'dropdown-menu-right'
  }

  const Default = {
  }

  let floatingDropdown = null


  /**
   * Class Definition
   * ====================================================
   */

  class Dropdown {
    constructor(element, config) {
      this._config  = config
      this._element = element
      this._menu = null
      this._popper = null
    }

    // Public

    toggleSubmenu() {
      this._element.siblings().toggleClass("show")

      if (!this._element.next().hasClass('show')) {
        this.hide()
      }

      if (! this._element.next().hasClass('show')) {
        this._element.parents('.dropdown-menu').first().find('.show').removeClass("show")
      }

      this._element.parents('li.nav-item.dropdown.show').on('hidden.bs.dropdown', function(e) {
        $('.dropdown-submenu .show').removeClass("show")
      })
    }

    fixPosition() {
      let elm = this._element.next(Selector.DROPDOWN_MENU_ACTIVE)

      if (elm.length && this._element.parent().hasClass('grid-dropdown-actions') &&
          this._element.parentsUntil(document.body).toArray().some(parent => {
            const style = window.getComputedStyle(parent)
            return /(auto|scroll|hidden|clip)/.test(style.overflowX + ' ' + style.overflowY)
          })) {
        this.float(elm)
        return
      }

      if (elm.length !== 0) {
        if (elm.hasClass(ClassName.DROPDOWN_RIGHT)) {
          elm.css('left', 'inherit')
          elm.css('right', 0)
        } else {
          elm.css('left', 0)
          elm.css('right', 'inherit')
        }

        let offset = elm.offset()
        let width = elm.width()
        let windowWidth = $(window).width()
        let visiblePart = windowWidth - offset.left

        if (offset.left < 0) {
          elm.css('left', 'inherit')
          elm.css('right', (offset.left - 5))
        } else {
          if (visiblePart < width) {
            elm.css('left', 'inherit')
            elm.css('right', 0)
          }
        }
      }  
    }

    float(menu) {
      if (this._popper) {
        this._popper.scheduleUpdate()
        return
      }

      if (floatingDropdown && floatingDropdown !== this) floatingDropdown.hide()
      floatingDropdown = this
      this._menu = menu
      this._menuStyle = menu[0].getAttribute('style')
      // Fixed positioning escapes table overflow without changing row/action ancestry.
      this._popper = new window.Popper(this._element[0], menu[0], {
        positionFixed: true,
        placement: menu.hasClass(ClassName.DROPDOWN_RIGHT) ? 'bottom-end' : 'bottom-start',
        modifiers: {
          preventOverflow: {boundariesElement: 'viewport'},
          flip: {boundariesElement: 'viewport'}
        }
      })
    }

    hide() {
      const menu = this._menu || this._element.next(Selector.DROPDOWN_MENU)
      menu.removeClass('show')
      if (this._popper) {
        this._popper.destroy()
        if (this._menuStyle === null) menu.removeAttr('style')
        else menu.attr('style', this._menuStyle)
        this._popper = this._menu = null
      }
      if (floatingDropdown === this) floatingDropdown = null
    }

    static hideAll(except) {
      if (floatingDropdown && floatingDropdown._menu[0] !== except) floatingDropdown.hide()
      $(Selector.DROPDOWN_MENU_ACTIVE).each(function () {
        if (this === except) return
        const instance = $(this).prev(Selector.DROPDOWN_TOGGLE).data(DATA_KEY)
        if (instance) instance.hide()
        else $(this).removeClass('show')
      })
    }

    // Static

    static _jQueryInterface(config) {
      return this.each(function () {
        let data      = $(this).data(DATA_KEY)
        const _config = $.extend({}, Default, $(this).data())

        if (!data) {
          data = new Dropdown($(this), _config)
          $(this).data(DATA_KEY, data)
        }

        if (config === 'toggleSubmenu' || config == 'fixPosition') {
          data[config]()
        }
      })
    }
  }

  /**
   * Data API
   * ====================================================
   */
  // $(Selector.DROPDOWN_TOGGLE).on("click", function(event) {
  //   event.preventDefault()
  //   event.stopPropagation()
  //
  //   Dropdown._jQueryInterface.call($(this), 'toggleSubmenu')
  // });
  //
  // $(Selector.DROPDOWN_TOGGLE).on("click", function(event) {
  //   event.preventDefault()
  //
  //   setTimeout(function() {
  //     Dropdown._jQueryInterface.call($(this), 'fixPosition')
  //   }, 1)
  // });

  /**
   * jQuery API
   * ====================================================
   */

  $.fn[NAME] = Dropdown._jQueryInterface
  $.fn[NAME].Constructor = Dropdown
  $.fn[NAME].noConflict = function () {
    $.fn[NAME] = JQUERY_NO_CONFLICT
    return Dropdown._jQueryInterface
  }

  return Dropdown
})(jQuery)

export default Dropdown
