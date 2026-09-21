@php
    $titre = "Ordi'Space — Ordinateurs livrés chez vous et espaces pour chaque acteur";
    $description = "Achetez des ordinateurs neufs, quasi neufs ou reconditionnés livrés chez vous, avec garantie. Livreurs, fournisseurs, commerciaux : chaque acteur a son espace.";
    $texteDePartage = "Découvrez Ordi'Space : ordinateurs neufs, quasi neufs ou reconditionnés livrés chez vous, avec garantie et paiement à la réception. Choisissez votre espace 👉 ".url('/');
    $imagePartage = asset('images/landing/og-ordispace.png');

    // Pictogrammes (traits, 24×24) — contenu statique de confiance, jamais issu d'une saisie.
    $icones = [
        'client' => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20c.5-3.8 3.6-6 7.5-6s7 2.2 7.5 6"/>',
        'livreur' => '<rect x="2" y="7" width="12" height="9" rx="1"/><path d="M14 10h4l4 3v3h-8z"/><circle cx="6.5" cy="18" r="1.7"/><circle cx="16.5" cy="18" r="1.7"/>',
        'fournisseur' => '<path d="M4 9l1.5-4.5h13L20 9"/><path d="M4 9v10.5h16V9"/><path d="M4 9c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3"/>',
        'commercial' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v9a2.5 2.5 0 0 1-2.5 2.5H10l-4.5 4v-4A2.5 2.5 0 0 1 4 14.5v-9Z"/><path d="M8.5 8.5h7M8.5 12h4"/>',
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titre }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="theme-color" content="#1d63e0">
    <link rel="canonical" href="{{ url('/') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}">

    {{-- Aperçu du lien quand la page est partagée (WhatsApp, Facebook, LinkedIn, X…) --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Ordi'Space">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:title" content="{{ $titre }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:image" content="{{ $imagePartage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $titre }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $imagePartage }}">

    <script type="application/ld+json">
        {!! json_encode(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => "Ordi'Space", 'url' => url('/'), 'logo' => asset('images/landing/mascotte.png'), 'description' => $description], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --bleu: #1d63e0; --bleu-vif: #0077ff; --cyan: #00bfff;
            --orange: #ff7a00; --jaune: #ffb800;
            --encre: #0b1b3a; --gris: #64748b; --ligne: #e6eaf2; --fond: #f4f7ff;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body { font-family: 'Nunito Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: var(--encre); background: var(--fond); line-height: 1.55; -webkit-font-smoothing: antialiased; }
        a { color: inherit; text-decoration: none; }
        img, svg { display: block; max-width: 100%; }
        .conteneur { width: min(1120px, 100% - 40px); margin-inline: auto; }
        :focus-visible { outline: 3px solid var(--jaune); outline-offset: 3px; border-radius: 8px; }

        /* Barre de navigation */
        .nav { position: sticky; top: 0; z-index: 30; background: rgba(255,255,255,.86); backdrop-filter: blur(12px); border-bottom: 1px solid var(--ligne); }
        .nav .conteneur { display: flex; align-items: center; gap: 18px; height: 64px; }
        .logo { font-weight: 900; font-size: 20px; letter-spacing: .04em; color: var(--bleu); }
        .nav nav { margin-left: auto; display: flex; align-items: center; gap: 22px; font-weight: 700; font-size: 14px; color: var(--gris); }
        .nav nav a:hover { color: var(--bleu); }
        .bouton { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: 0; cursor: pointer; font: inherit; font-weight: 800; border-radius: 14px; padding: 13px 22px; transition: transform .15s, box-shadow .15s; }
        .bouton:hover { transform: translateY(-2px); }
        .bouton-orange { color: #fff; background: linear-gradient(90deg, var(--orange), var(--jaune)); box-shadow: 0 8px 20px rgba(255,122,0,.3); }
        .bouton-blanc { color: var(--bleu); background: #fff; box-shadow: 0 6px 18px rgba(0,40,120,.18); }
        .bouton-contour { color: #fff; background: rgba(255,255,255,.15); border: 1.5px solid rgba(255,255,255,.55); }
        .bouton-bleu { color: #fff; background: linear-gradient(135deg, var(--bleu-vif), var(--cyan)); box-shadow: 0 8px 20px rgba(0,119,255,.28); }
        .bouton-neutre { color: var(--bleu); background: #e8f1fe; }
        .bouton-petit { padding: 9px 16px; font-size: 14px; border-radius: 12px; }

        /* Héros */
        .heros { position: relative; overflow: hidden; color: #fff; background: linear-gradient(135deg, var(--bleu-vif), var(--cyan)); border-radius: 0 0 48px 48px; }
        .heros::before, .heros::after { content: ""; position: absolute; border-radius: 50%; background: rgba(255,255,255,.08); }
        .heros::before { width: 520px; height: 520px; right: -140px; top: -200px; }
        .heros::after { width: 320px; height: 320px; left: -110px; bottom: -150px; }
        .heros .conteneur { position: relative; display: grid; grid-template-columns: 1.15fr .85fr; align-items: center; gap: 36px; padding-block: 76px 88px; }
        .etiquette { display: inline-block; background: rgba(255,255,255,.2); border-radius: 999px; padding: 6px 14px; font-size: 13px; font-weight: 800; letter-spacing: .03em; }
        .heros h1 { margin-top: 16px; font-size: clamp(34px, 5.4vw, 56px); line-height: 1.08; font-weight: 900; letter-spacing: -.02em; }
        .heros p.chapeau { margin-top: 18px; max-width: 560px; font-size: clamp(16px, 2vw, 19px); color: rgba(255,255,255,.93); }
        .actions { margin-top: 28px; display: flex; flex-wrap: wrap; gap: 12px; }
        .atouts { margin-top: 30px; display: flex; flex-wrap: wrap; gap: 10px; }
        .atouts li { list-style: none; background: #fff; color: var(--bleu); font-weight: 800; font-size: 13px; border-radius: 999px; padding: 7px 14px; }
        .visuel { display: flex; justify-content: center; }
        .visuel .carte { position: relative; width: min(340px, 100%); aspect-ratio: 1; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,.16); border: 1.5px solid rgba(255,255,255,.4); border-radius: 44px; box-shadow: 0 26px 50px rgba(0,50,140,.25); }
        .visuel img { width: 74%; filter: drop-shadow(0 14px 18px rgba(0,40,120,.3)); }
        .puce { position: absolute; background: #fff; color: var(--encre); font-size: 13px; font-weight: 800; border-radius: 14px; padding: 9px 14px; box-shadow: 0 10px 24px rgba(0,40,120,.22); }
        .puce b { color: var(--orange); }
        .puce.haut { top: 22px; left: -18px; } .puce.bas { bottom: 26px; right: -14px; }

        /* Sections */
        section { padding-block: 72px 0; }
        .entete { text-align: center; max-width: 660px; margin: 0 auto 36px; }
        .entete h2 { font-size: clamp(26px, 3.6vw, 36px); font-weight: 900; letter-spacing: -.015em; line-height: 1.15; }
        .entete p { margin-top: 10px; color: var(--gris); font-size: 17px; }
        .groupe { margin-top: 34px; }
        .groupe h3 { font-size: 20px; font-weight: 900; }
        .groupe > p { color: var(--gris); margin-top: 2px; }
        .grille { margin-top: 18px; display: grid; gap: 18px; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); }
        .espace { display: flex; flex-direction: column; background: #fff; border: 1px solid var(--ligne); border-radius: 26px; padding: 26px; box-shadow: 0 10px 30px rgba(0,60,160,.07); transition: transform .2s, box-shadow .2s; }
        .espace.actif:hover { transform: translateY(-4px); box-shadow: 0 18px 40px rgba(0,60,160,.14); }
        .espace .tete { display: flex; align-items: center; gap: 14px; }
        .icone { flex: none; width: 52px; height: 52px; border-radius: 16px; display: flex; align-items: center; justify-content: center; color: #fff; background: linear-gradient(135deg, var(--bleu-vif), var(--cyan)); }
        .icone svg { width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .espace h4 { font-size: 20px; font-weight: 900; }
        .statut { display: inline-block; margin-top: 3px; font-size: 11px; font-weight: 800; border-radius: 999px; padding: 2px 10px; }
        .statut.ok { color: #16a34a; background: #ddf7e8; } .statut.bientot { color: #c2740a; background: #fff0d3; }
        .espace .accroche { margin-top: 16px; font-weight: 700; }
        .espace ul { margin: 14px 0 22px; display: grid; gap: 8px; color: var(--gris); font-size: 15px; }
        .espace li { list-style: none; position: relative; padding-left: 26px; }
        .espace li::before { content: ""; position: absolute; left: 0; top: 4px; width: 16px; height: 16px; border-radius: 50%; background: #ddf7e8 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2316a34a' stroke-width='3.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 12.5 4 4 8-9'/%3E%3C/svg%3E") center/11px no-repeat; }
        .espace .bas { margin-top: auto; display: flex; flex-wrap: wrap; gap: 10px; }
        .espace .bas .bouton { flex: 1 1 auto; }
        /* Groupe à une seule plateforme : carte "vedette" pleine largeur (texte à gauche, points à droite). */
        .grille.seul { grid-template-columns: 1fr; }
        .grille.seul .espace { display: grid; grid-template-columns: 1fr 1fr; grid-template-areas: "tete points" "accroche points" "bas points"; grid-template-rows: auto auto 1fr; column-gap: 48px; align-items: start; }
        .grille.seul .tete { grid-area: tete; } .grille.seul .accroche { grid-area: accroche; font-size: 20px; }
        .grille.seul ul { grid-area: points; align-self: center; margin: 0; font-size: 16px; }
        .grille.seul .bas { grid-area: bas; align-self: end; margin-top: 22px; } .grille.seul .bas .bouton { flex: 0 0 auto; }
        .espace.bientot-dispo { background: #fbfcff; }
        .espace.bientot-dispo .icone { background: #cbd5e1; }
        .bouton-inactif { color: #94a3b8; background: #eef1f6; cursor: not-allowed; }
        .bouton-inactif:hover { transform: none; }

        /* Étapes */
        .etapes { display: grid; gap: 18px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .etape { background: #fff; border: 1px solid var(--ligne); border-radius: 24px; padding: 26px; }
        .etape .num { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 900; color: #fff; background: linear-gradient(90deg, var(--orange), var(--jaune)); }
        .etape h4 { margin-top: 14px; font-size: 18px; font-weight: 900; }
        .etape p { margin-top: 6px; color: var(--gris); }

        /* Bandeau "lien reçu" et partage */
        .bandeau { color: #fff; border-radius: 30px; padding: 34px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 22px; background: linear-gradient(120deg, var(--orange), var(--jaune)); box-shadow: 0 16px 36px rgba(255,122,0,.25); }
        .bandeau h3 { font-size: 24px; font-weight: 900; line-height: 1.2; }
        .bandeau p { margin-top: 6px; max-width: 620px; color: rgba(255,255,255,.95); }
        .partage { text-align: center; background: #fff; border: 1px solid var(--ligne); border-radius: 30px; padding: 38px 24px; }
        .partage h2 { font-size: clamp(24px, 3.2vw, 32px); font-weight: 900; }
        .partage p { margin: 8px auto 0; max-width: 560px; color: var(--gris); }
        .boutons-partage { margin-top: 24px; display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; }
        .bouton-whatsapp { color: #fff; background: #25d366; box-shadow: 0 8px 20px rgba(37,211,102,.3); }
        .adresse { margin: 22px auto 0; display: inline-block; max-width: 100%; overflow-wrap: anywhere; background: var(--fond); border: 1px dashed #b9c6e6; border-radius: 12px; padding: 9px 16px; font-weight: 700; color: var(--bleu); }
        .copie { display: none; margin-top: 12px; color: #16a34a; font-weight: 800; font-size: 14px; }
        .copie.visible { display: block; }

        footer { margin-top: 80px; background: var(--encre); color: #cbd5e1; padding-block: 44px; }
        footer .conteneur { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 20px; }
        footer .logo { color: #fff; }
        footer small { display: block; margin-top: 4px; color: #94a3b8; }
        footer a.contact { display: inline-flex; align-items: center; gap: 8px; color: #fff; font-weight: 800; background: #25d366; border-radius: 12px; padding: 10px 18px; }

        @media (max-width: 860px) {
            .heros .conteneur { grid-template-columns: 1fr; padding-block: 52px 64px; }
            .visuel { order: -1; } .visuel .carte { width: 210px; border-radius: 34px; }
            .puce.haut { left: -8px; top: 10px; } .puce.bas { right: -8px; }
            .nav nav a.lien-nav { display: none; }
            .heros { border-radius: 0 0 34px 34px; }
            .grille.seul .espace { display: flex; }
            .grille.seul ul { margin: 14px 0 22px; } .grille.seul .bas .bouton { flex: 1 1 auto; }
            section { padding-block: 56px 0; }
        }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } * { transition: none !important; } }
    </style>
</head>
<body>
    <header class="nav">
        <div class="conteneur">
            <a href="{{ url('/') }}" class="logo" aria-label="Ordi'Space — accueil">ORDI'SPACE</a>
            <nav aria-label="Navigation principale">
                <a class="lien-nav" href="#espaces">Nos espaces</a>
                <a class="lien-nav" href="#acheter">Comment acheter</a>
                <a class="lien-nav" href="#partager">Partager</a>
                <a class="bouton bouton-bleu bouton-petit" href="#espaces">Choisir mon espace</a>
            </nav>
        </div>
    </header>

    <main>
        <div class="heros">
            <div class="conteneur">
                <div>
                    <span class="etiquette">Côte d'Ivoire</span>
                    <h1>Tout l'univers informatique, à portée de clic.</h1>
                    <p class="chapeau">Achetez des ordinateurs neufs, quasi neufs ou reconditionnés, livrés chez vous avec garantie. Livreurs, fournisseurs, commerciaux : chaque acteur d'Ordi'Space a son propre espace.</p>
                    <div class="actions">
                        <a class="bouton bouton-orange" href="#espaces">Choisir mon espace →</a>
                        <a class="bouton bouton-contour" href="#partager">Partager cette page</a>
                    </div>
                    <ul class="atouts">
                        <li>Livraison à domicile</li>
                        <li>Paiement à la réception</li>
                        <li>Garantie</li>
                    </ul>
                </div>
                <div class="visuel" aria-hidden="true">
                    <div class="carte">
                        <img src="{{ asset('images/landing/mascotte.png') }}" alt="" width="203" height="202">
                        <span class="puce haut">Neuf · <b>Quasi neuf</b></span>
                        <span class="puce bas">Reconditionné · <b>Accessoires</b></span>
                    </div>
                </div>
            </div>
        </div>

        <section id="espaces">
            <div class="conteneur">
                <div class="entete">
                    <h2>Une plateforme, un espace pour chacun</h2>
                    <p>Dites-nous qui vous êtes : nous vous envoyons directement au bon endroit.</p>
                </div>

                @foreach ($groupes as $groupe)
                    <div class="groupe">
                        <h3>{{ $groupe['titre'] }}</h3>
                        <p>{{ $groupe['description'] }}</p>
                        <div class="grille {{ count($groupe['plateformes']) === 1 ? 'seul' : '' }}">
                            @foreach ($groupe['plateformes'] as $p)
                                @php $disponible = $p['url'] !== null; @endphp
                                <article class="espace {{ $disponible ? 'actif' : 'bientot-dispo' }}" id="espace-{{ $p['cle'] }}">
                                    <div class="tete">
                                        <span class="icone" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $icones[$p['icone']] !!}</svg></span>
                                        <div>
                                            <h4>{{ $p['nom'] }}</h4>
                                            <span class="statut {{ $disponible ? 'ok' : 'bientot' }}">{{ $disponible ? 'Disponible' : 'Bientôt disponible' }}</span>
                                        </div>
                                    </div>
                                    <p class="accroche">{{ $p['accroche'] }}</p>
                                    <ul>
                                        @foreach ($p['points'] as $point)
                                            <li>{{ $point }}</li>
                                        @endforeach
                                    </ul>
                                    <div class="bas">
                                        @if ($disponible)
                                            <a class="bouton bouton-bleu" href="{{ $p['url'] }}" rel="noopener">Accéder à cet espace →</a>
                                        @else
                                            <span class="bouton bouton-inactif" aria-disabled="true">Ouverture prochaine</span>
                                            @if ($whatsappSupport)
                                                <a class="bouton bouton-neutre" href="{{ $whatsappSupport }}?text={{ rawurlencode("Bonjour, je souhaite être prévenu de l'ouverture de l'".$p['nom']." Ordi'Space.") }}" target="_blank" rel="noopener noreferrer">Me prévenir</a>
                                            @endif
                                        @endif
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section id="acheter">
            <div class="conteneur">
                <div class="entete">
                    <h2>Acheter, c'est simple</h2>
                    <p>Trois étapes, et vous ne payez qu'à la réception.</p>
                </div>
                <div class="etapes">
                    <div class="etape"><span class="num">1</span><h4>Choisissez</h4><p>Parcourez les ordinateurs et accessoires depuis l'espace client, ou ouvrez le lien envoyé par votre conseiller.</p></div>
                    <div class="etape"><span class="num">2</span><h4>Commandez</h4><p>Indiquez votre nom, votre téléphone et votre adresse de livraison. Un lien de vente ne demande aucun compte.</p></div>
                    <div class="etape"><span class="num">3</span><h4>Recevez et payez</h4><p>Notre équipe vous rappelle pour confirmer, puis un livreur vous apporte votre commande. Paiement à la réception.</p></div>
                </div>
            </div>
        </section>

        <section>
            <div class="conteneur">
                <div class="bandeau">
                    <div>
                        <h3>Vous avez reçu un lien ou un QR code ?</h3>
                        <p>Ouvrez-le directement : vous arrivez sur le produit choisi par votre conseiller Ordi'Space et pouvez commander en quelques secondes, sans créer de compte.</p>
                    </div>
                    <a class="bouton bouton-blanc" href="#espaces">Découvrir les espaces</a>
                </div>
            </div>
        </section>

        <section id="partager">
            <div class="conteneur">
                <div class="partage">
                    <h2>Faites connaître Ordi'Space</h2>
                    <p>Un seul lien à envoyer à vos contacts, clients ou partenaires : chacun choisit son espace et arrive au bon endroit.</p>
                    <div class="boutons-partage">
                        <a class="bouton bouton-whatsapp" href="https://wa.me/?text={{ rawurlencode($texteDePartage) }}" target="_blank" rel="noopener noreferrer">Partager sur WhatsApp</a>
                        <button type="button" class="bouton bouton-bleu" id="copier">Copier le lien</button>
                        <button type="button" class="bouton bouton-neutre" id="partager-natif" hidden>Autres applications…</button>
                    </div>
                    <span class="adresse" id="adresse">{{ url('/') }}</span>
                    <p class="copie" id="message-copie" role="status">Lien copié !</p>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="conteneur">
            <div>
                <span class="logo">ORDI'SPACE</span>
                <small>© {{ date('Y') }} Ordi'Space — Côte d'Ivoire. Tous droits réservés.</small>
            </div>
            @if ($whatsappSupport)
                <a class="contact" href="{{ $whatsappSupport }}" target="_blank" rel="noopener noreferrer">Nous écrire sur WhatsApp</a>
            @endif
        </div>
    </footer>

    <script>
        (function () {
            var adresse = @json(url('/'));
            var titre = @json($titre);
            var texte = @json($texteDePartage);
            var bouton = document.getElementById('copier');
            var message = document.getElementById('message-copie');
            var natif = document.getElementById('partager-natif');

            function confirmer() {
                message.classList.add('visible');
                setTimeout(function () { message.classList.remove('visible'); }, 2200);
            }

            bouton.addEventListener('click', function () {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(adresse).then(confirmer, function () { window.prompt('Copiez ce lien :', adresse); });
                } else {
                    window.prompt('Copiez ce lien :', adresse);
                }
            });

            // Partage natif (feuille de partage du téléphone) quand le navigateur le propose.
            if (navigator.share) {
                natif.hidden = false;
                natif.addEventListener('click', function () {
                    navigator.share({ title: titre, text: texte, url: adresse }).catch(function () {});
                });
            }
        })();
    </script>
</body>
</html>
