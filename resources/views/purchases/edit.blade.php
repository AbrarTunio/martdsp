<x-app-layout :title="$purchase->reference">
    <x-flash />

    <x-page-header :title="__('Edit :reference', ['reference' => $purchase->reference])"
                   :description="__('Not received yet, so nothing on the shelf or on the supplier\'s account has changed.')" />

    <form method="POST" action="{{ route('purchases.update', $purchase) }}" class="mt-5">
        @csrf
        @method('PUT')

        @include('purchases._form', ['cancelUrl' => route('purchases.show', $purchase)])
    </form>
</x-app-layout>
