@props(['size' => 'h-14 w-14'])

{{--
    The tick that says a thing went through.

    It is drawn rather than flashed on, because a cashier working fast reads
    the movement, not the words. It falls back to a plain finished tick when
    the device is set to reduce motion, or when JavaScript never arrives.
--}}
<span {{ $attributes->merge(['class' => 'mx-auto grid place-items-center rounded-full '.$size]) }} x-tick>
    <svg viewBox="0 0 52 52" class="h-2/3 w-2/3 overflow-visible" fill="none" aria-hidden="true">
        <circle data-ring cx="26" cy="26" r="24" stroke="currentColor" stroke-width="2" opacity="0.4"
                style="transform-box: fill-box; transform-origin: center;" />
        <path data-check d="M15 27l8 8 14-16" stroke="currentColor" stroke-width="4"
              stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</span>
