@extends('layouts.page')

@section('title', 'Valider un paiement Wave · Jibiat Marchand')

@section('content')
    <main class="mx-auto max-w-md px-4 py-10">
        <img src="/brand/logo-256.png" alt="Jibiat Marchand" class="h-12 w-12 rounded-xl">
        <h1 class="mt-5 text-2xl font-bold">Paiement Wave à vérifier</h1>

        <dl class="mt-6 space-y-3 rounded-2xl bg-slate-50 p-5 text-sm ring-1 ring-slate-200">
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Montant</dt><dd class="text-lg font-bold">{{ $amount }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Numéro Wave</dt><dd class="font-semibold">{{ $payer ?? '—' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Espace marchand</dt><dd class="text-right">{{ $invoice->store?->name }}@if ($payerName) · {{ $payerName }}@endif</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Objet</dt><dd class="text-right">{{ $label }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-slate-500">Facture</dt><dd>{{ $invoice->number }} · {{ $invoice->created_at->timezone('Africa/Abidjan')->format('d/m/Y H:i') }}</dd></div>
        </dl>

        @if ($invoice->status === 'paid')
            <p class="mt-6 rounded-xl bg-emerald-50 p-4 font-medium text-emerald-800 ring-1 ring-emerald-200">✓ Paiement confirmé : l'abonnement est actif et l'e-commerçant est prévenu.</p>
        @elseif ($invoice->status === 'failed')
            <p class="mt-6 rounded-xl bg-amber-50 p-4 font-medium text-amber-900 ring-1 ring-amber-200">Paiement marqué non reçu : l'e-commerçant est prévenu.</p>
        @else
            <p class="mt-6 text-sm text-slate-600">Vérifiez dans Wave que ce montant est bien arrivé depuis ce numéro, puis confirmez avec votre code.</p>
            @if ($error)
                <p class="mt-4 rounded-lg bg-red-50 p-3 text-sm font-medium text-red-700">{{ $error }}</p>
            @endif
            <form method="post" action="{{ request()->fullUrl() }}" class="mt-4 space-y-4">
                @csrf
                <label class="block text-sm font-medium">Code de validation
                    <input name="code" type="password" inputmode="numeric" autocomplete="one-time-code" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2.5 text-base">
                </label>
                <button name="decision" value="received" class="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white">Paiement reçu : activer</button>
                <button name="decision" value="missing" class="w-full rounded-lg px-4 py-3 font-medium text-slate-600 ring-1 ring-slate-300">Paiement non reçu</button>
            </form>
        @endif
    </main>
@endsection
