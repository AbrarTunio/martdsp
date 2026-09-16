@props(['name', 'class' => 'w-6 h-6'])

{{--
    Line icons drawn from simple primitives so the whole set is one small file
    with no icon-font or package dependency. 24x24 grid, 1.5 stroke.
--}}
<svg {{ $attributes->merge(['class' => $class]) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('home')
            <path d="M3 10.5 12 3l9 7.5" />
            <path d="M5.25 9.75V20.25h13.5V9.75" />
            <path d="M9.75 20.25v-6h4.5v6" />
            @break

        @case('cart')
            <path d="M2.25 3.75H4.5l2.4 11.1h11.1l2.25-8.1H6" />
            <circle cx="8.25" cy="19.5" r="1.5" />
            <circle cx="17.25" cy="19.5" r="1.5" />
            @break

        @case('box')
            <path d="M3.75 7.5 12 3.75l8.25 3.75L12 11.25z" />
            <path d="M3.75 7.5v9L12 20.25l8.25-3.75v-9" />
            <path d="M12 11.25v9" />
            @break

        @case('layers')
            <path d="M12 3.75 3.75 7.5 12 11.25l8.25-3.75z" />
            <path d="m3.75 12 8.25 3.75L20.25 12" />
            <path d="m3.75 16.5 8.25 3.75 8.25-3.75" />
            @break

        @case('truck')
            <path d="M1.5 6.75h12.75v9H1.5z" />
            <path d="M14.25 10.5h3.75l3.75 3.5v1.75h-7.5z" />
            <circle cx="6.75" cy="18.25" r="1.75" />
            <circle cx="17.25" cy="18.25" r="1.75" />
            @break

        @case('factory')
            <path d="M4.5 20.25V4.5a.75.75 0 0 1 .75-.75h9a.75.75 0 0 1 .75.75v15.75" />
            <path d="M15 9.75h3.75a.75.75 0 0 1 .75.75v9.75" />
            <path d="M3 20.25h18" />
            <path d="M7.5 7.5h1.5M11.25 7.5h1.5M7.5 11.25h1.5M11.25 11.25h1.5M7.5 15h1.5M11.25 15h1.5" />
            @break

        @case('book')
            <path d="M4.5 4.5A1.5 1.5 0 0 1 6 3h13.5v15H6a1.5 1.5 0 0 0-1.5 1.5z" />
            <path d="M4.5 19.5A1.5 1.5 0 0 0 6 21h13.5" />
            @break

        @case('wallet')
            <path d="M2.25 9.75h19.5v8.25a1.5 1.5 0 0 1-1.5 1.5H3.75a1.5 1.5 0 0 1-1.5-1.5z" />
            <path d="M4.5 9.75 6 5.25h12l1.5 4.5" />
            <path d="M9.75 14.25h4.5" />
            @break

        @case('chart')
            <path d="M3.75 3.75v16.5h16.5" />
            <path d="M7.875 16.5v-5.25M12 16.5V7.5M16.125 16.5v-3" />
            @break

        @case('cog')
            <path d="M4.5 6.75h15M4.5 12h15M4.5 17.25h15" />
            <circle cx="9" cy="6.75" r="1.75" fill="currentColor" stroke="none" />
            <circle cx="15" cy="12" r="1.75" fill="currentColor" stroke="none" />
            <circle cx="9" cy="17.25" r="1.75" fill="currentColor" stroke="none" />
            @break

        @case('menu')
            <path d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
            @break

        @case('close')
            <path d="m6 6 12 12M18 6 6 18" />
            @break

        @case('logout')
            <path d="M15.75 9V5.25a1.5 1.5 0 0 0-1.5-1.5h-7.5a1.5 1.5 0 0 0-1.5 1.5v13.5a1.5 1.5 0 0 0 1.5 1.5h7.5a1.5 1.5 0 0 0 1.5-1.5V15" />
            <path d="M12 12h9.75" />
            <path d="m18.75 9 3 3-3 3" />
            @break

        @case('user')
            <circle cx="12" cy="8.25" r="3.75" />
            <path d="M4.875 20.25a7.5 7.5 0 0 1 14.25 0" />
            @break

        @case('plus')
            <path d="M12 4.5v15M4.5 12h15" />
            @break

        @case('search')
            <circle cx="10.5" cy="10.5" r="6.75" />
            <path d="m15.75 15.75 4.5 4.5" />
            @break

        @case('sparkles')
            <path d="M9 3.75l1.6 4.15L14.75 9.5l-4.15 1.6L9 15.25l-1.6-4.15L3.25 9.5l4.15-1.6z" />
            <path d="M17.625 13.5l.825 2.175 2.175.825-2.175.825-.825 2.175-.825-2.175L14.625 16.5l2.175-.825z" />
            @break

        @case('scan')
            <path d="M3.75 7.5V5.25a1.5 1.5 0 0 1 1.5-1.5H7.5M16.5 3.75h2.25a1.5 1.5 0 0 1 1.5 1.5V7.5M20.25 16.5v2.25a1.5 1.5 0 0 1-1.5 1.5H16.5M7.5 20.25H5.25a1.5 1.5 0 0 1-1.5-1.5V16.5" />
            <path d="M7.5 8.25v7.5M10.5 8.25v7.5M13.5 8.25v7.5M16.5 8.25v7.5" />
            @break

        @case('chevron-right')
            <path d="m9 5.25 6.75 6.75L9 18.75" />
            @break

        @case('dots')
            <circle cx="5.25" cy="12" r="1.5" fill="currentColor" stroke="none" />
            <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
            <circle cx="18.75" cy="12" r="1.5" fill="currentColor" stroke="none" />
            @break

        @case('sun')
            <circle cx="12" cy="12" r="4.5" />
            <path d="M12 2.25v2.25M12 19.5v2.25M4.22 4.22l1.6 1.6M18.18 18.18l1.6 1.6M2.25 12h2.25M19.5 12h2.25M4.22 19.78l1.6-1.6M18.18 5.82l1.6-1.6" />
            @break

        @case('moon')
            <path d="M20.25 14.4A8.25 8.25 0 0 1 9.6 3.75 8.25 8.25 0 1 0 20.25 14.4z" />
            @break

        @case('alert')
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8.25v4.5M12 16.125h.008" />
            @break

        @case('check')
            <path d="m4.5 12.75 5.25 5.25 9.75-12" />
            @break

        @case('printer')
            <path d="M6.75 8.25V3.75h10.5v4.5" />
            <path d="M4.5 8.25h15a1.5 1.5 0 0 1 1.5 1.5v6h-3.75v4.5H6.75v-4.5H3v-6a1.5 1.5 0 0 1 1.5-1.5z" />
            <path d="M6.75 15.75h10.5" />
            @break

        @case('receipt')
            <path d="M5.25 3h13.5v18l-2.25-1.5L14.25 21 12 19.5 9.75 21 7.5 19.5 5.25 21z" />
            <path d="M8.25 7.5h7.5M8.25 11.25h7.5M8.25 15h4.5" />
            @break

        @case('camera')
            <path d="M3 8.25a1.5 1.5 0 0 1 1.5-1.5h2.25L8.25 4.5h7.5l1.5 2.25h2.25a1.5 1.5 0 0 1 1.5 1.5v9.75a1.5 1.5 0 0 1-1.5 1.5h-15a1.5 1.5 0 0 1-1.5-1.5z" />
            <circle cx="12" cy="12.75" r="3.75" />
            @break

        @case('minus')
            <path d="M4.5 12h15" />
            @break

        @case('pause')
            <path d="M9 5.25v13.5M15 5.25v13.5" />
            @break

        @case('trash')
            <path d="M4.5 6.75h15M9.75 6.75V4.5h4.5v2.25M6.75 6.75l.75 13.5h9l.75-13.5" />
            @break

        @case('safe')
            <path d="M3.75 4.5h16.5v14.25H3.75z" />
            <circle cx="12" cy="11.625" r="3" />
            <path d="M12 8.625v.75M12 13.875v.75M15 11.625h-.75M9.75 11.625H9" />
            <path d="M6 18.75v1.5M18 18.75v1.5" />
            @break

        @case('lock')
            <path d="M5.25 10.5h13.5v9.75H5.25z" />
            <path d="M8.25 10.5V7.5a3.75 3.75 0 0 1 7.5 0v3" />
            <path d="M12 14.25v2.25" />
            @break

        @case('unlock')
            <path d="M5.25 10.5h13.5v9.75H5.25z" />
            <path d="M8.25 10.5V7.5a3.75 3.75 0 0 1 7.3-1.2" />
            <path d="M12 14.25v2.25" />
            @break

        @case('clock')
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7.5V12l3 1.875" />
            @break

        @case('download')
            <path d="M12 3.75v11.25" />
            <path d="m7.5 10.5 4.5 4.5 4.5-4.5" />
            <path d="M3.75 15.75v3a1.5 1.5 0 0 0 1.5 1.5h13.5a1.5 1.5 0 0 0 1.5-1.5v-3" />
            @break

        @case('trend-up')
            <path d="M3 17.25 9 11.25l3.75 3.75L21 6.75" />
            <path d="M15.75 6.75H21v5.25" />
            @break

        @case('trend-down')
            <path d="M3 6.75 9 12.75l3.75-3.75L21 17.25" />
            <path d="M15.75 17.25H21V12" />
            @break

        @case('refresh')
            <path d="M20.25 12a8.25 8.25 0 0 1-14.1 5.85" />
            <path d="M3.75 12a8.25 8.25 0 0 1 14.1-5.85" />
            <path d="M17.85 2.25v3.9h-3.9" />
            <path d="M6.15 21.75v-3.9h3.9" />
            @break

        @case('users')
            <circle cx="9" cy="8.25" r="3" />
            <path d="M3.75 19.5a5.25 5.25 0 0 1 10.5 0" />
            <path d="M15.75 5.4a3 3 0 0 1 0 5.7" />
            <path d="M17.25 14.4a5.25 5.25 0 0 1 3 5.1" />
            @break

        @default
            <circle cx="12" cy="12" r="9" />
    @endswitch
</svg>
