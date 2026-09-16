<x-app-layout :title="__('New delivery')">
    <x-flash />

    <x-page-header :title="__('New delivery')"
                   :description="__('Scan what came off the van, check the total against the bill, and receive it into stock.')" />

    <form method="POST" action="{{ route('purchases.store') }}" class="mt-5">
        @csrf

        @include('purchases._form', ['cancelUrl' => route('purchases.index')])
    </form>
</x-app-layout>
