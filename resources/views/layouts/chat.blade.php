<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body data-surface="consumer" class="min-h-screen bg-zinc-100 dark:bg-zinc-950">
        <header class="flex h-14 items-center border-b border-zinc-200/80 bg-white/90 px-3 backdrop-blur-xl dark:border-zinc-800 dark:bg-zinc-900/90 sm:px-5">
            <x-app-logo href="{{ route('chat') }}" wire:navigate />

            <span class="ml-3 hidden rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300 sm:inline-flex">
                知识助手
            </span>

            <flux:spacer />

            <div class="flex items-center gap-2">
                <flux:button size="sm" variant="ghost" icon="cog-6-tooth" :href="route('admin.dashboard')" wire:navigate>
                    <span class="hidden sm:inline">管理后台</span>
                </flux:button>

                <flux:dropdown position="bottom" align="end">
                    <flux:profile
                        :initials="auth()->user()->initials()"
                        icon-trailing="chevron-down"
                    />

                    <flux:menu>
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <flux:avatar
                                :name="auth()->user()->name"
                                :initials="auth()->user()->initials()"
                            />
                            <div class="grid min-w-0 flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                            </div>
                        </div>

                        <flux:menu.separator />

                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            账号设置
                        </flux:menu.item>

                        <flux:menu.separator />

                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item
                                as="button"
                                type="submit"
                                icon="arrow-right-start-on-rectangle"
                                class="w-full cursor-pointer"
                                data-test="logout-button"
                            >
                                退出登录
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </header>

        <main class="h-[calc(100dvh-3.5rem)] p-2 sm:p-4">
            {{ $slot }}
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
