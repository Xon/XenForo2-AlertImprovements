/*
 * This file is part of a XenForo add-on.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

var SV = window.SV || {};

(function($, window, document, _undefined) {
    "use strict";

    SV.alertUnreadClick = XF.Click.newHandler({
        eventNameSpace: 'SVAlertClick',

        options: {
        },

        init: function()
        {
        },

        click: function(e)
        {
            e.preventDefault();

            var href = this.$target.attr('href');
            XF.ajax(
                'POST',
                href,
                {},
                $.proxy(this, 'handleAjax'),
                {skipDefaultSuccess: true}
            );
        },

        /**
         * @param {Object} data
         */
        handleAjax: function(data)
        {
            var $target = this.$target;

            $target.hide();
            $target.closest('li.block-row[data-alert-id]').addClass('block-row--highlighted');
        }
    });

    XF.Click.register('mark-alert-unread', 'SV.alertUnreadClick');
} (jQuery, window, document));
