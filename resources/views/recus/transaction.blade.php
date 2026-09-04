<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Reçu #{{ $id }}</title>
    <style>
        body { font-family: sans-serif; color: #1a1a1a; font-size: 13px; }
        .en-tete { border-bottom: 2px solid #0077FF; padding-bottom: 12px; margin-bottom: 20px; }
        .en-tete h1 { color: #0077FF; margin: 0 0 4px; font-size: 20px; }
        .en-tete p { margin: 0; color: #666; }
        .statut { display: inline-block; padding: 4px 12px; border-radius: 12px; font-weight: bold; }
        .statut-paye { background: #E6F7EE; color: #1B8A4C; }
        .statut-en_attente { background: #FFF1E0; color: #C97A15; }
        .montant { font-size: 24px; font-weight: bold; color: #0077FF; margin: 12px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        td { padding: 8px 0; border-bottom: 1px solid #eee; }
        td.label { color: #888; width: 40%; }
        td.valeur { font-weight: bold; text-align: right; }
    </style>
</head>
<body>
    <div class="en-tete">
        <h1>OrdiSpace</h1>
        <p>Reçu de transaction — Portefeuille fournisseur</p>
    </div>

    <p>
        @if ($reference_paiement)
            Référence de paiement : <strong>{{ $reference_paiement }}</strong><br>
        @endif
        <span class="statut statut-{{ $statut }}">{{ $statut === 'paye' ? 'Payé' : 'À Payer' }}</span>
    </p>

    <div class="montant">{{ number_format($montant, 0, ',', ' ') }} CFA</div>

    <table>
        @if ($nom_produit)
            <tr>
                <td class="label">Produit</td>
                <td class="valeur">{{ $nom_produit }}</td>
            </tr>
        @endif
        @if ($prix_vente_total)
            <tr>
                <td class="label">Prix de vente</td>
                <td class="valeur">{{ number_format($prix_vente_total, 0, ',', ' ') }} CFA</td>
            </tr>
        @endif
        <tr>
            <td class="label">Date &amp; heure</td>
            <td class="valeur">{{ \Illuminate\Support\Carbon::parse($date_transaction)->translatedFormat('d/m/Y à H:i') }}</td>
        </tr>
        @if ($nom_fournisseur)
            <tr>
                <td class="label">Fournisseur</td>
                <td class="valeur">{{ $nom_fournisseur }}</td>
            </tr>
        @endif
        @if ($nom_gerant)
            <tr>
                <td class="label">Gérant</td>
                <td class="valeur">{{ $nom_gerant }}</td>
            </tr>
        @endif
    </table>
</body>
</html>
