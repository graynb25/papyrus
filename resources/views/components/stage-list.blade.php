{{--
    One stage list for the launcher's detailed run, the live backup and restore
    timeline and the import phases. Key, status and title are required; attempts
    and message belong to the launcher's stages only.
--}}
@props(['stages'])

<ol class="mt-4 space-y-2" role="list">
    @foreach ($stages as $stage)
        @php
            $key = (string) ($stage['key'] ?? '');
            $status = (string) ($stage['status'] ?? 'pending');
            $title = (string) ($stage['title'] ?? str_replace('_', ' ', $key));
            $message = is_string($stage['message'] ?? null) ? (string) $stage['message'] : '';
            $attempts = (int) ($stage['attempts'] ?? 1);
        @endphp

        <li role="listitem" class="flex items-center gap-3 py-2">
            <span
                class="flex size-5 flex-none items-center justify-center rounded-full border {{ match ($status) {
                    'done' => 'border-zinc-400 bg-zinc-200 text-zinc-700 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-200',
                    'running' => 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900',
                    'failed' => 'border-red-500 bg-red-500 text-white',
                    default => 'border-zinc-300 bg-transparent text-transparent dark:border-zinc-700',
                } }}"
                aria-hidden="true"
            >
                @if ($status === 'done')
                    &#10003;
                @elseif ($status === 'failed')
                    &#10005;
                @elseif ($status === 'running')
                    <flux:icon name="loading" class="size-3" />
                @endif
            </span>

            <div class="min-w-0">
                <p class="text-sm font-medium">
                    {{ $title }}
                    @if ($attempts > 1)
                        <span class="ml-1 text-xs font-normal text-amber-700 dark:text-amber-400">
                            {{ __('tried :count times', ['count' => $attempts]) }}
                        </span>
                    @endif
                </p>

                @if ($status === 'failed' && $message !== '')
                    <p class="text-xs text-red-700 dark:text-red-300">{{ $message }}</p>
                @elseif ($status === 'skipped')
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Not needed for this backup.') }}</p>
                @elseif ($message !== '')
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $message }}</p>
                @endif
            </div>
        </li>
    @endforeach
</ol>
