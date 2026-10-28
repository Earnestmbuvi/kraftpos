<div x-data="{
        items: [],
        add(message, type = 'success') {
            const id = Date.now() + Math.random();
            this.items.push({ id, message, type });
            setTimeout(() => { this.items = this.items.filter(i => i.id !== id) }, 4000);
        }
    }"
    x-on:notify.window="add($event.detail.message ?? 'Done.', $event.detail.type ?? 'success')"
    @if (session('notify')) x-init="add(@js(session('notify')))" @endif
    class="fixed right-4 top-20 z-[60] w-80 max-w-[calc(100vw-2rem)] space-y-2 print:hidden"
    aria-live="polite">
    <template x-for="item in items" :key="item.id">
        <div x-transition
             class="flex items-start gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg"
             :class="item.type === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-green-200 bg-green-50 text-green-800'">
            <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
            <span x-text="item.message" class="flex-1"></span>
            <button type="button" @click="items = items.filter(i => i.id !== item.id)" class="text-lg leading-none opacity-60 hover:opacity-100" aria-label="Dismiss">&times;</button>
        </div>
    </template>
</div>
