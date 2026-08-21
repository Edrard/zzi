@php
    $articles = $getState() ?? [];
@endphp

@if (empty($articles))
    <div class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('znuny_ticket_workspace.accordion.no_articles') }}
    </div>
@else
    <div 
        x-data="{
            activeArticle: null,
            findScrollableAncestor(el) {
                while (el && el !== document.body && el !== document.documentElement) {
                    const overflowY = window.getComputedStyle(el).overflowY;
                    const isScrollable = (overflowY === 'auto' || overflowY === 'scroll');
                    if (isScrollable && el.scrollHeight > el.clientHeight) {
                        return el;
                    }
                    el = el.parentElement;
                }
                return document.querySelector('[role=\'dialog\']') || document.querySelector('.fi-modal-window') || document.querySelector('.fi-modal-content');
            },
            scrollOpenedArticleIntoFocus(index, behavior = 'smooth') {
                const container = this.findScrollableAncestor(this.$refs.articlesAccordion);
                const item = this.$refs.articlesAccordion.querySelector(`[data-article-index='${index}']`);

                if (!container || !item) {
                    return;
                }

                const header = item.querySelector('.zbx-ticket-article-header') || item;
                const containerRect = container.getBoundingClientRect();
                const itemRect = item.getBoundingClientRect();
                const headerRect = header.getBoundingClientRect();

                const modalWindow = container.closest('.fi-modal-window');
                const footer = modalWindow ? modalWindow.querySelector('.fi-modal-footer') : null;
                const footerHeight = footer ? footer.getBoundingClientRect().height : 0;

                const topComfort = 16;
                const bottomComfort = 16 + footerHeight;

                const visibleTop = containerRect.top + topComfort;
                const visibleBottom = containerRect.bottom - bottomComfort;
                const availableHeight = Math.max(80, visibleBottom - visibleTop);

                let targetScrollTop = null;

                if (itemRect.height > availableHeight) {
                    targetScrollTop = container.scrollTop + headerRect.top - visibleTop;
                } else if (itemRect.bottom > visibleBottom) {
                    targetScrollTop = container.scrollTop + itemRect.bottom - visibleBottom;
                } else if (headerRect.top < visibleTop) {
                    targetScrollTop = container.scrollTop + headerRect.top - visibleTop;
                }

                if (targetScrollTop === null) {
                    return;
                }

                const maxScrollTop = container.scrollHeight - container.clientHeight;
                targetScrollTop = Math.max(0, Math.min(targetScrollTop, maxScrollTop));

                container.scrollTo({ top: targetScrollTop, behavior });
            },
            activateInlineImages(index) {
                const item = this.$refs.articlesAccordion.querySelector(`[data-article-index='${index}']`);
                if (!item) return;

                const images = item.querySelectorAll('img[data-znuny-inline-src]');
                images.forEach(img => {
                    if (!img.getAttribute('src')) {
                        img.setAttribute('src', img.getAttribute('data-znuny-inline-src'));
                        img.removeAttribute('data-znuny-inline-src');
                    }
                });
            },
            toggleArticle(index) {
                if (this.activeArticle === index) {
                    this.activeArticle = null;
                } else {
                    this.activeArticle = index;
                    this.$nextTick(() => {
                        this.activateInlineImages(index);
                        this.scrollOpenedArticleIntoFocus(index, 'smooth');
                        requestAnimationFrame(() => {
                            this.scrollOpenedArticleIntoFocus(index, 'smooth');
                            setTimeout(() => this.scrollOpenedArticleIntoFocus(index, 'smooth'), 120);
                            setTimeout(() => this.scrollOpenedArticleIntoFocus(index, 'auto'), 280);
                        });
                    });
                }
            }
        }"
        x-ref="articlesAccordion"
        class="zbx-ticket-articles flex flex-col gap-2"
    >
        @foreach ($articles as $index => $article)
            <div data-article-index="{{ $index }}" class="zbx-ticket-article-item overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <button 
                    type="button" 
                    @click="toggleArticle({{ $index }})"
                    class="zbx-ticket-article-header flex w-full items-center justify-between px-3 py-2 text-left transition hover:bg-gray-50 dark:hover:bg-white/5 focus-visible:bg-gray-50 dark:focus-visible:bg-white/5"
                >
                    <div class="flex items-center gap-2 truncate pr-4">
                        @if (!empty($article['is_visible_for_customer']))
                            <div title="{{ __('znuny_ticket_workspace.accordion.article') }}" aria-label="{{ __('znuny_ticket_workspace.accordion.article') }}" class="inline-flex items-center text-primary-600 dark:text-primary-400 shrink-0">
                                <x-heroicon-m-chat-bubble-left-ellipsis class="h-4 w-4" />
                            </div>
                        @else
                            <div title="{{ __('znuny_ticket_workspace.accordion.internal_note') }}" aria-label="{{ __('znuny_ticket_workspace.accordion.internal_note') }}" class="inline-flex items-center text-gray-500 dark:text-gray-400 shrink-0">
                                <x-heroicon-m-lock-closed class="h-4 w-4" />
                            </div>
                        @endif
                        <span class="text-sm font-medium text-gray-950 dark:text-white truncate">
                            {{ !empty($article['subject']) ? $article['subject'] : __('znuny_ticket_workspace.accordion.no_subject') }}
                        </span>
                    </div>
                    <span 
                        class="text-gray-400 transition-transform duration-200 dark:text-gray-500 shrink-0" 
                        :class="{ 'rotate-180': activeArticle === {{ $index }} }"
                    >
                        <x-heroicon-m-chevron-down class="h-5 w-5" />
                    </span>
                </button>

                <div 
                    x-show="activeArticle === {{ $index }}" 
                    x-collapse
                    x-cloak
                >
                    <div class="zbx-ticket-article-body px-3 pb-2 pt-1 text-sm text-gray-700 dark:text-gray-300">
                        <div class="mb-2 flex flex-wrap gap-x-4 gap-y-1">
                            @if(!empty($article['created_at']))
                                <div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 block mb-0.5">{{ __('znuny_ticket_workspace.accordion.created') }}</span>
                                    <span>{{ app(\App\Services\Support\DateTimeDisplayService::class)->formatLocalizedDateTime($article['created_at']) }}</span>
                                </div>
                            @endif
                            @if(!empty($article['sender_type']))
                                <div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 block mb-0.5">{{ __('znuny_ticket_workspace.accordion.sender') }}</span>
                                    @php
                                        $rawSenderType = (string) $article['sender_type'];
                                        $senderTypeKey = strtolower($rawSenderType);
                                        $translationKey = "zabbix_tickets.sender_types.{$senderTypeKey}";
                                        $localizedSender = __($translationKey);
                                        if ($localizedSender === $translationKey) {
                                            $localizedSender = $rawSenderType;
                                        }
                                    @endphp
                                    <span>{{ $localizedSender }}</span>
                                </div>
                            @endif
                        </div>
                        @php
                            $renderer = app(\App\Services\Znuny\ZnunyArticleBodyRenderer::class);
                            $rendered = $renderer->render($article);
                            $bodyContent = $rendered['content'];
                        @endphp
                        @if ($bodyContent === '')
                            <div class="zbx-ticket-article-text whitespace-pre-wrap break-words rounded-md bg-gray-50 dark:bg-white/5 text-sm leading-snug ring-1 ring-gray-950/5 dark:ring-white/10 overflow-x-auto">{{ __('znuny_ticket_workspace.accordion.no_body') }}</div>
                        @elseif ($rendered['is_html'])
                            <div class="zbx-ticket-article-html zbx-ticket-article-text break-words rounded-md bg-white dark:bg-gray-900 text-sm leading-snug ring-1 ring-gray-950/5 dark:ring-white/10 overflow-x-auto p-3">{!! $bodyContent !!}</div>
                        @else
                            <div class="zbx-ticket-article-text whitespace-pre-wrap break-words rounded-md bg-gray-50 dark:bg-white/5 text-sm leading-snug ring-1 ring-gray-950/5 dark:ring-white/10 overflow-x-auto p-3">{{ $bodyContent }}</div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
