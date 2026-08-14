# Hook `qutlet_product_title_generated` (P-9.1a.2)

**Status: zarezerwowany, bez subskrybenta.** Hook istnieje i jest odpalany już
dziś, ale świadomie NIC się do niego nie podpina — to punkt zaczepienia pod
przyszły mechanizm notyfikacji (np. Slack/e-mail do kuratora), którego teraz
NIE budujemy (decyzja użytkownika, sesja 2026-08-14, P-9.1a).

## Sygnatura

```php
do_action( 'qutlet_product_title_generated', int $product_id, string $tytul, string $podnazwa );
```

- `$product_id` — id produktu WooCommerce (post ID).
- `$tytul` — zapisany `post_title` (już zsanityzowany, plain text).
- `$podnazwa` — zapisana wartość pola ACF `podnazwa` (`field_qutlet_podnazwa`, może być pusty string).

## Kiedy się odpala

W `Qutlet\Ai\AiRewrite\TitleWriter::accept()` — PO udanym zapisie
`post_title` + `podnazwa`. `accept()` jest wspólną ścieżką zapisu dla OBU
akcji metaboxa „Qutlet — nazwa produktu (AI)”:

- **Generuj** (`TitleGenerationMetaBox::handle_generate()`) — `$tytul`/`$podnazwa`
  pochodzą z generacji AI (`TitleGenerator::generate()`).
- **Reset** (`TitleGenerationMetaBox::handle_reset()`) — `$tytul` to oryginalna
  nazwa Allegro (`RawLayerMeta::META_NAME_RAW`), `$podnazwa` to zawsze `''`.

Hook NIE odróżnia tych dwóch ścieżek (brak np. parametru `$source`) — jeśli
przyszły subskrybent będzie musiał je rozróżnić, to rozszerzenie sygnatury
(nowy parametr) w osobnym punkcie planu, nie coś do zgadywania z boku.

## Powiązany mechanizm: flaga „Nowy”

Ten sam commit (P-9.1a.2) dodał stempel `TitleWriter::SOURCE_RAW_META`
(`_qutlet_ai_title_source_raw`) — nazwa Allegro, z której powstał BIEŻĄCY
tytuł/podnazwa, zapisywana przy każdym `accept()`. `TitleGenerationMetaBox`
porównuje ten stempel z aktualną `RawLayerMeta::META_NAME_RAW` i pokazuje
badge „Nowy”, gdy sync (`qutlet-allegro`, `ProductWriter`) zaktualizował
warstwę surową PO ostatniej generacji/resecie — sygnał dla kuratora, że
oferta zmieniła się na Allegro i warto zweryfikować tytuł.

Przyszły mechanizm notyfikacji mógłby subskrybować się w drugą stronę —
wysyłać powiadomienie, gdy sync wykryje rozjazd (nie gdy `accept()` go
ROZWIĄZUJE, co robi ten hook) — to inny punkt zaczepienia, prawdopodobnie w
`qutlet-allegro::ProductWriter::upsert()` przy zapisie warstwy surowej, nie
tutaj. Nie zakładamy z góry, który kierunek faktycznie wybierzemy — ten
dokument tylko opisuje, co ISTNIEJE dziś.
