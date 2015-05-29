(function($) {

    $.widget("mapbender.mbBaseSourceSwitcher", {
        options: {
        },
        scalebar: null,
        readyState: false,
        readyCallbacks: [],
        _create: function() {
            if (!Mapbender.checkTarget("mbBaseSourceSwitcher", this.options.target)) {
                return;
            }
            var self = this;
            Mapbender.elementRegistry.onElementReady(this.options.target, $.proxy(self._setup, self));
        },

        _setup: function() {
            var self = this;

            //TOMY_setWidth/display
            var display = this.options.display;

            var widthLi = [];
            var count = $(".mb-element-basesourceswitcher li").size();
            if (display !== 'mobile') {
                while (count > 0) {
                    widthLi[count - 1] = $(".mb-element-basesourceswitcher li").eq(count - 1).width() + 30;
                    count--;
                }

            var largest = Math.max.apply(Math, widthLi) + 20 + "px";
            $(".mb-element-basesourceswitcher li").css("width", largest);
            if (display === "dropdown") {
                $('.mb-element-basesourceswitcher').addClass("dropdownbs");

            }
            }
            $('.basesourcesetswitch', this.element).click($.proxy(self._toggleMapset, self));
            $('.basesourcegroup', this.element).click(function(e) {

                var bsswtch = $('.basesourcesubswitcher', $(e.currentTarget));
                var substate = bsswtch.hasClass('hidden');
                $('.basesourcesubswitcher', $(self.element)).addClass('hidden');
                if (substate) {
                    $('.basesourcesubswitcher', $(this)).removeClass('hidden');
                } else {
                    $('.basesourcesubswitcher', $(this)).addClass('hidden');

                }
            });

            this._hideSources();
            this._showActive();
            self._trigger('ready');
            this._ready();
            if (display === "dropdown") {
                this.setHeight();
            }
        },

        getCpr: function() {
            var me = $(this.element);
            var element = me.find('.basesourcesetswitch[data-state="active"]');
            var index = element.index();
            var i = 0;
            var cprTitle, cprUrl;
            for (var gridx in this.options.groups) {
                if (i === index) {
                    var gr = this.options.groups[gridx];
                    cprTitle = gr.cprTitle;
                    cprUrl = gr.cprUrl;
                    break;
                }
                i++;
            }
            return {
                name: cprTitle,
                url: cprUrl
            }
        },

        _hideSources: function() {
            var me = $(this.element),
                map = $('#' + this.options.target).data('mapbenderMbMap'),
                model = map.getModel();
            var ids = [];
            $.each(model.getSources(), function(_, src) {
                if(src.configuration.isBaseSource) {
                    ids.push(src.id);
                }
            });
            $.each(ids, function(idx, elm) {
                var tochange = {
                    change: {
                        sourceIdx: {
                            id: elm
                        },
                        options: {
                            configuration: {
                                options: {
                                    visibility: false
                                }
                            },
                            type: 'selected'
                        }
                    }
                };
                model.changeSource(tochange);
            });
        },

        _showActive: function() {
            var me = $(this.element),
                map = $('#' + this.options.target).data('mapbenderMbMap'),
                model = map.getModel(),
                eventOptions = [];
            $.each(me.find('.basesourcesetswitch[data-state="active"]'), function(idx, elm) {
                eventOptions.push({
                    title: "",
                    href: ""
                });
                var sourcesIds = $(elm).attr("data-sourceset").split(",");
                for (var i = 0; i < sourcesIds.length; i++) {
                    if (sourcesIds[i] !== '') {
                        var source = model.getSource({
                            origId: sourcesIds[i]
                        });
                        if (source) {
                            var tochange = {
                                change: {
                                    sourceIdx: {
                                        id: source.id
                                    },
                                    options: {
                                        configuration: {
                                            options: {
                                                visibility: true
                                            }
                                        },
                                        type: 'selected'
                                    }
                                }
                            };
                            model.changeSource(tochange);
                        }
                    }
                }
            });
        },

        setHeight: function() {
            $('.basesourcesetswitch[data-state="active"]').addClass("iconLegend");
            var dis = $('.basesourcesetswitch[data-state="active"]').offset().top;
            var heightLi = $('.basesourcesetswitch[data-state="active"]').height();
            var size = $('.mb-element-basesourceswitcher li').size();
            var konst;
            for (var i = 1; i < size; i++) {
                if ($('.mb-element-basesourceswitcher li').eq(i).attr("data-state") === "") {
                    $('.mb-element-basesourceswitcher li').eq(i).css("top", dis + 1.5 * heightLi + "px");
                }
                if (i === 1) {
                    konst = heightLi;
                }
                heightLi = heightLi + konst;
            }
            heightLi = 0;

        },

        _toggleMapset: function(event) {
            var me = $(this.element),
                a = $(event.currentTarget);
            var display = this.options.display;
            var konst;

            if (display === "dropdown") {
                if (a.attr("data-state") === "active") {
                    $('.basesourcesetswitch[data-state=""]').css("display", "block");
                    $('.mb-element-basesourceswitcher').mouseleave(function() {
                        $('.basesourcesetswitch[data-state=""]').slideUp();
                    });
                }
                else {
                    a = $(event.currentTarget);
                    this._hideSources();
                    //setHeight
                    var dis = $('.basesourcesetswitch[data-state="active"]').offset().top;
                    var heightLi = $(a).height();
                    var size = $('.basesourcesetswitch').size();
                    me.find('.basesourcesetswitch,.basesourcegroup').not(a).attr('data-state', '');
                    a.attr('data-state', 'active');
                    a.parents('.basesourcegroup:first').attr('data-state', 'active').addClass('hidden');
                    a.parents('.basesourcesubswitcher:first').addClass('hidden');

                    //setHeight
                    $('.mb-element-basesourceswitcher li').css("top", 0);
                    for (var i = 0; i < size; i++) {
                        if ($('.mb-element-basesourceswitcher li').eq(i).attr("data-state") === "") {
                            $('.mb-element-basesourceswitcher li').eq(i).css("top", dis + 1.5 * heightLi + "px");

                            if ($(a).height() === heightLi) {
                                konst = heightLi;
                            }

                            heightLi = heightLi + konst;
                        }
                    }
                    $('.basesourcesetswitch[data-state="active"]').css("top", 0);
                    $('.basesourcesetswitch[data-state=""]').css("display", "none").removeClass("iconLegend");
                    $('.basesourcesetswitch[data-state="active"]').addClass("iconLegend");

                    if (a.hasClass('notgroup')) {
                        $('.basesourcesubswitcher', me).addClass('hidden');
                    }
                    this._trigger('groupactivate', null);
                    this._showActive();



                }
            }
            else {
                this._hideSources();
                me.find('.basesourcesetswitch,.basesourcegroup').not(a).attr('data-state', '');
                a.attr('data-state', 'active');
                a.parents('.basesourcegroup:first').attr('data-state', 'active').addClass('hidden');
                a.parents('.basesourcesubswitcher:first').addClass('hidden');
                if (a.hasClass('notgroup')) {
                    $('.basesourcesubswitcher', me).addClass('hidden');
                }
                this._showActive();
                this._trigger('groupactivate', null);

                return false;
            }
        },

        /**
         *
         */
        ready: function(callback) {
            if (this.readyState === true) {
                callback();
            } else {
                this.readyCallbacks.push(callback);
            }
        },

        /**
         *
         */
        _ready: function() {
            for (var callback in this.readyCallbacks) {
                callback();
                delete(this.readyCallbacks[callback]);
            }
            this.readyState = true;
        },

        _destroy: $.noop

    });

})(jQuery);