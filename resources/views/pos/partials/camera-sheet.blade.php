{{--
    The phone's camera as a scanner, for a counter with no USB scanner. Only
    offered where the browser can read barcodes itself (Chrome on Android,
    over HTTPS), so there is nothing extra to install.
--}}
<x-pos.sheet name="camera" :title="__('Scan with the camera')">
    <div class="relative overflow-hidden rounded-xl bg-black">
        <video x-ref="cameraVideo" playsinline muted class="aspect-[4/3] w-full object-cover"></video>
        <div class="pointer-events-none absolute inset-x-8 top-1/2 h-0.5 -translate-y-1/2 bg-red-500/80 shadow-[0_0_8px_rgba(239,68,68,0.8)]"></div>
    </div>

    <p class="mt-3 text-center text-sm text-gray-600 dark:text-gray-400">
        {{ __('Hold the barcode flat, inside the frame, about a hand-span away.') }}
    </p>

    <template x-if="camera.error">
        <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200" x-text="camera.error"></p>
    </template>
</x-pos.sheet>
