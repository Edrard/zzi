@php
    $articles = $getState() ?? [];
@endphp

@if (empty($articles))
    <div class="text-sm text-gray-500 dark:text-gray-400">
        No articles found.
    </div>
@else
    <div 
        x-data="{ activeArticle: null }" 
        class="zbx-ticket-articles flex flex-col gap-2"
    >
        @foreach ($articles as $index => $article)
            <div class="zbx-ticket-article-item overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <button 
                    type="button" 
                    @click="activeArticle = (activeArticle === {{ $index }} ? null : {{ $index }})"
                    class="zbx-ticket-article-header flex w-full items-center justify-between px-3 py-2 text-left transition hover:bg-gray-50 dark:hover:bg-white/5 focus-visible:bg-gray-50 dark:focus-visible:bg-white/5"
                >
                    <span class="text-sm font-medium text-gray-950 dark:text-white truncate pr-4">
                        {{ !empty($article['subject']) ? $article['subject'] : 'No subject' }}
                    </span>
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
                    <div class="zbx-ticket-article-body px-3 pb-3 pt-1 text-sm text-gray-700 dark:text-gray-300">
                        <div class="mb-3 flex flex-wrap gap-x-6 gap-y-2">
                            @if(!empty($article['created_at']))
                                <div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 block mb-0.5">Created</span>
                                    <span>{{ app(\App\Services\Support\DateTimeDisplayService::class)->formatDateTime($article['created_at']) }}</span>
                                </div>
                            @endif
                            @if(!empty($article['sender_type']))
                                <div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 block mb-0.5">Sender</span>
                                    <span class="capitalize">{{ $article['sender_type'] }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="zbx-ticket-article-text whitespace-pre-wrap break-words rounded-md bg-gray-50 dark:bg-white/5 p-3 text-sm leading-relaxed ring-1 ring-gray-950/5 dark:ring-white/10 overflow-x-auto">
                            {{ !empty($article['body']) ? $article['body'] : 'No body' }}
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
