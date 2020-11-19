/*
 * This file is part of a XenForo add-on.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

var SV = window.SV || {};
SV.AlertImprovements = SV.AlertImprovements || {};

(function($, window, document, _undefined) {
    "use strict";

    SV.AlertImprovements.AlertToggler = XF.Event.newHandler({
        eventNameSpace: 'SVAlertImprovementsAlertTogglerClick',
        eventType: 'click',

        options: {
            successMessageFlashTimeOut: 3000
        },

        processing: null,

        init: function()
        {
            this.processing = false;
        },

        /**
         * @param {Event} e
         */
        click: function(e)
        {
            e.preventDefault();

            if (this.processing)
            {
                return;
            }
            this.processing = true;

            let self = this,
                $target = this.$target,
                $alert = $target.closest('.js-alert'),
                inList = $alert.find("input[name='alert_ids[]']").length > 0;

            XF.ajax('POST', $target.attr('href'), {
                inlist: inList ? 1 : 0
            }, $.proxy(this, 'handleMarkReadAjax')).always(function ()
            {
                self.processing = false;
            });
        },

        /**
         * @param {Object} data
         */
        handleMarkReadAjax: function(data)
        {
            if (data.message)
            {
                XF.flashMessage(data.message, this.options.successMessageFlashTimeOut);
            }

            let $target = this.$target,
                $alert = $target.closest('.js-alert'),
                wasUnread = $alert.hasClass('is-unread');

            $alert.removeClass('is-read');
            if (wasUnread)
            {
                $alert.addClass('is-recently-read').removeClass('is-unread');
            }
            else
            {
                $alert.removeClass('is-recently-read').addClass('is-unread');
            }
            if (data.html)
            {
                XF.setupHtmlInsert(data.html, function($html, data, onComplete)
                {
                    $alert.empty();
                    $alert.append($html);
                });
            }


        }
    });

    XF.Click.register('mark-alert-unread', 'SV.AlertImprovements.AlertToggler');
} (jQuery, window, document));
