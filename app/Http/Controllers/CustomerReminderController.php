<?php

namespace App\Http\Controllers;

use App\Enums\CustomerEntryType;
use App\Models\Customer;
use App\Support\KhataAging;
use App\Support\KhataReminder;
use Illuminate\Contracts\View\View;

/**
 * The message to send a customer who has let their khata sit.
 *
 * The shop sends it itself — from its own WhatsApp, in its own name. All this
 * page does is write the words and get the amount right, so nobody has to
 * look the balance up twice.
 */
class CustomerReminderController extends Controller
{
    public function __invoke(Customer $customer): View
    {
        $aging = KhataAging::for($customer);

        $lastPayment = $customer->ledgerEntries()
            ->where('type', CustomerEntryType::Payment)
            ->latestFirst()
            ->first();

        return view('customers.reminder', [
            'customer' => $customer,
            'aging' => $aging,
            'message' => KhataReminder::message($customer, $aging, $lastPayment),
            'whatsapp' => KhataReminder::whatsappNumber($customer->phone),
        ]);
    }
}
