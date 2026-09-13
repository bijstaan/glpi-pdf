<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpipdf;

use TCPDF;

/**
 * Paints a {@see Doc} onto pages. The only class in the suite that knows TCPDF
 * exists.
 *
 * ### Why the blocks become HTML in here
 *
 * Most blocks are drawn by handing markup to TCPDF's `writeHTML()` rather than
 * by placing cells at coordinates. That looks like a contradiction of the whole
 * point of the block model, and is not: the markup is *generated here*, from
 * data, in one place, and no contributor ever sees or writes any of it. What it
 * buys is the part of PDF generation nobody should hand-roll — flowing text
 * across a page break, splitting a forty-row table over three pages and
 * repeating its header on each, keeping a cell's borders intact when it breaks.
 * Doing that with `Cell()` and `MultiCell()` means reimplementing pagination,
 * and reimplementing pagination is how PDF exporters end up with a row sliced
 * in half at the bottom of page two.
 *
 * The pieces TCPDF's HTML engine genuinely cannot do — the masthead, the rule
 * under it, the footer, the meter bars — are drawn directly.
 *
 * ### Fonts
 *
 * DejaVu Sans, not Helvetica, and this is a correctness decision rather than a
 * typographic one. The PDF core fonts have no glyphs outside Latin-1: TCPDF
 * silently substitutes `?`, so a completed SOP run — whose whole notation is
 * `✓` for done and `⊘` for skipped — exports as a column of question marks, and
 * nothing anywhere reports an error. Verified against this instance: helvetica
 * rendered `? check ? skip ?`, dejavusans rendered `⌇ check ✓ skip ⊘`.
 *
 * The cost is real and is accepted: an embedded subset adds roughly 45 KB to
 * every file. A document that says `?` where it means "not done" is not worth
 * 45 KB less.
 */
final class Engine extends TCPDF
{
    /** Page geometry, in millimetres. */
    private const MARGIN_X       = 15.0;
    private const MARGIN_BOTTOM  = 18.0;
    private const HEADER_HEIGHT  = 20.0;
    private const LOGO_WIDTH     = 26.0;
    /** The running header's mark, sized to sit clear of the rule under it. */
    private const HEADER_LOGO_HEIGHT = 7.0;

    private const FONT = 'dejavusans';

    private Branding $brand;
    private Doc $doc;

    /** Suppressed on the first page, where the masthead is the title block. */
    private bool $running_header = false;

    public static function render(Doc $doc, ?Branding $brand = null): string
    {
        $engine = new self($doc, $brand ?? Branding::resolve());

        return $engine->paint();
    }

    private function __construct(Doc $doc, Branding $brand)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8');

        $this->doc   = $doc;
        $this->brand = $brand;

        // Never GLPI, and never this plugin either. The metadata of a file that
        // reaches an entity is part of what the file says.
        $this->SetCreator($brand->name);
        $this->SetAuthor($brand->name);
        $this->SetTitle(trim($doc->reference . ' ' . $doc->title));
        $this->SetSubject($doc->subtitle);

        $this->SetMargins(self::MARGIN_X, self::HEADER_HEIGHT + 6.0, self::MARGIN_X);
        $this->SetAutoPageBreak(true, self::MARGIN_BOTTOM);
        $this->SetFont(self::FONT, '', 9);

        // TCPDF's own header/footer machinery is replaced wholesale — see
        // Header() and Footer() below — so its margins only decide where the
        // page body starts.
        $this->setHeaderMargin(0);
        $this->setFooterMargin(10);
    }

    private function paint(): string
    {
        $this->AddPage();
        $this->masthead();

        // The running header starts from page two: page one already carries the
        // full masthead, and a second copy of the brand 8 mm above it reads as
        // a rendering fault.
        $this->running_header = true;

        foreach ($this->doc->blocks as $block) {
            $this->block($block);
        }

        if (trim($this->doc->footnote) !== '') {
            $this->Ln(4);
            $this->emit(
                "<p style=\"font-size:7pt;color:#7b8794;\">" . self::e($this->doc->footnote) . '</p>'
            );
        }

        return (string) $this->Output('', 'S');
    }

    // ------------------------------------------------------- page furniture

    /**
     * The running header, on every page but the first.
     *
     * TCPDF calls this during `AddPage()`, including the automatic one at a
     * page break — which is why it is a flag rather than a page-number test:
     * the first page's `AddPage()` happens before the masthead is drawn, and
     * checking `getPage() > 1` would put a running header above it.
     */
    public function Header()
    {
        if (!$this->running_header) {
            return;
        }

        $y = 10.0;

        if ($this->brand->logo !== '') {
            // Constrained by *height*, not width. A logo's aspect ratio is
            // whatever the entity's designer chose, and sizing a square mark
            // to 16 mm wide makes it 16 mm tall — straight through the rule
            // below and into the first line of the page.
            $this->Image($this->brand->logo, self::MARGIN_X, $y - 1.5, 0, self::HEADER_LOGO_HEIGHT,
                '', '', 'T', true, 300);
        }

        $this->SetY($y);
        $this->SetFont(self::FONT, '', 7.5);
        $this->SetTextColor(...self::rgb('#7b8794'));
        $this->Cell(0, 5, self::plain($this->doc->title), 0, 0, 'R');

        $this->rule($y + 6.5);
        $this->SetTextColor(0, 0, 0);
    }

    public function Footer()
    {
        $this->SetY(-13);
        $this->SetFont(self::FONT, '', 7);
        $this->SetTextColor(...self::rgb('#98a2b3'));

        $left  = $this->brand->name !== '' ? self::plain($this->brand->name) : '';
        $right = sprintf('%s / %s', $this->getAliasNumPage(), $this->getAliasNbPages());

        $this->Cell(0, 5, $left, 0, 0, 'L');
        $this->Cell(0, 5, $right, 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * The first page's title block.
     *
     * The logo sits above the title rather than beside it. Beside it is what
     * the first attempt did, and a logo of any height then overlaps a title of
     * any length — the two are sized by different people at different times and
     * neither knows about the other. Stacking them costs 12 mm once and cannot
     * collide.
     */
    private function masthead(): void
    {
        $y = 12.0;

        if ($this->brand->logo !== '') {
            $this->Image($this->brand->logo, self::MARGIN_X, $y, self::LOGO_WIDTH, 0, '', '', 'T', true, 300);
            $y = max($y + 12.0, $this->GetImageRBY());
        }

        if ($this->brand->name !== '') {
            $this->SetY($y + 1);
            $this->SetX(self::MARGIN_X);
            $this->SetFont(self::FONT, 'B', 8);
            $this->SetTextColor(...self::rgb('#5b6472'));
            $this->Cell(0, 5, self::plain($this->brand->name), 0, 1, 'L');
            $y = $this->GetY();
        }

        $this->SetY($y + 3);
        $this->SetX(self::MARGIN_X);
        $this->SetFont(self::FONT, 'B', 17);
        $this->SetTextColor(...self::rgb('#16202e'));
        $this->MultiCell(0, 8, self::plain($this->doc->title), 0, 'L', false, 1);

        if ($this->doc->reference !== '' || $this->doc->subtitle !== '') {
            $line = trim(implode('  ·  ', array_filter([
                self::plain($this->doc->reference),
                self::plain($this->doc->subtitle),
            ])));

            $this->SetX(self::MARGIN_X);
            $this->SetFont(self::FONT, '', 9);
            $this->SetTextColor(...self::rgb('#6b7280'));
            $this->MultiCell(0, 5, $line, 0, 'L', false, 1);
        }

        $this->SetTextColor(0, 0, 0);
        $this->Ln(1.5);
        $this->rule($this->GetY(), $this->brand->accent, 0.6);
        $this->Ln(4);

        if ($this->doc->meta !== []) {
            $this->kv($this->doc->meta, true);
            $this->Ln(1);
        }
    }

    private function rule(float $y, string $colour = '#dde3ea', float $width = 0.2): void
    {
        $this->SetLineWidth($width);
        $this->SetDrawColor(...self::rgb($colour));
        $this->Line(self::MARGIN_X, $y, $this->getPageWidth() - self::MARGIN_X, $y);
        $this->SetLineWidth(0.2);
    }

    // -------------------------------------------------------------- blocks

    /** @param array<string,mixed> $block */
    private function block(array $block): void
    {
        match ((string) $block['type']) {
            'heading'   => $this->heading($block),
            'text'      => $this->paragraph($block),
            'kv'        => $this->kv((array) $block['pairs'], false),
            'table'     => $this->table($block),
            'checklist' => $this->checklist($block),
            'bullets'   => $this->bulletList($block),
            'timeline'  => $this->timelineBlock($block),
            'meter'     => $this->meterBar($block),
            'note'      => $this->noteBox($block),
            'pagebreak' => $this->AddPage(),
            'spacer'    => $this->Ln(3),
            default     => null,
        };
    }

    /** @param array<string,mixed> $block */
    private function heading(array $block): void
    {
        $level = (int) ($block['level'] ?? 1);

        // A heading at the very bottom of a page, with its content overleaf, is
        // the classic bad break. 24 mm of remaining space is roughly a heading
        // plus two rows; below that the heading goes with its content.
        if ($this->remaining() < 24.0) {
            $this->AddPage();
        }

        $this->Ln($level === 1 ? 3 : 2);

        $size   = $level === 1 ? 12.5 : 10.5;
        $colour = $level === 1 ? $this->brand->accent : '#3d4a5c';

        $this->SetX(self::MARGIN_X);
        $this->SetFont(self::FONT, 'B', $size);
        $this->SetTextColor(...self::rgb($colour));
        $this->MultiCell(0, $level === 1 ? 6.5 : 5.5, self::plain((string) $block['text']), 0, 'L', false, 1);
        $this->SetTextColor(0, 0, 0);

        if ($level === 1) {
            $this->rule($this->GetY() + 0.5);
            $this->Ln(2.5);
        } else {
            $this->Ln(0.5);
        }
    }

    /** @param array<string,mixed> $block */
    private function paragraph(array $block): void
    {
        $colour = !empty($block['muted']) ? '#6b7280' : '#1f2733';

        $this->emit(sprintf(
            '<p style="font-size:9pt;color:%s;line-height:1.5;">%s</p>',
            $colour,
            self::rich((string) $block['body'])
        ));
    }

    /**
     * @param array<string,string> $pairs
     * @param bool $masthead the identity block, which is tighter and greyer
     */
    private function kv(array $pairs, bool $masthead): void
    {
        $rows = '';
        foreach ($pairs as $label => $value) {
            $rows .= sprintf(
                '<tr><td width="28%%" style="color:#6b7280;font-size:%1$s;">%2$s</td>'
                . '<td width="72%%" style="font-size:%1$s;">%3$s</td></tr>',
                $masthead ? '8pt' : '8.5pt',
                self::e((string) $label),
                self::rich((string) $value)
            );
        }

        $this->emit(
            '<table cellpadding="' . ($masthead ? '1' : '2') . '" border="0">' . $rows . '</table>'
        );
    }

    /**
     * @param array<string,mixed> $block
     *
     * `thead` is not decoration: TCPDF repeats the rows inside it at the top of
     * each page a table spills onto. Without it a procedure of thirty steps
     * gives the reader a headerless grid of text from page two onwards.
     */
    private function table(array $block): void
    {
        $headers = (array) $block['headers'];
        $rows    = (array) $block['rows'];
        $widths  = self::widths((array) $block['widths'], count($headers));

        $html = '<table cellpadding="3" border="0"><thead><tr>';
        foreach ($headers as $i => $header) {
            $html .= sprintf(
                '<th width="%d%%" style="background-color:#eef2f7;color:#3d4a5c;font-size:8pt;'
                . 'border-bottom:0.4mm solid #cbd5e1;"><b>%s</b></th>',
                $widths[$i],
                self::e((string) $header)
            );
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $index => $row) {
            // Zebra striping rather than row rules: a rule per row on a
            // forty-row table is louder than the data in it.
            $shade = $index % 2 === 1 ? ' background-color:#f8fafc;' : '';
            $html .= '<tr>';
            foreach (array_values((array) $row) as $i => $cell) {
                $html .= sprintf(
                    '<td width="%d%%" style="font-size:8.5pt;%s">%s</td>',
                    $widths[$i] ?? (int) (100 / max(1, count($headers))),
                    $shade,
                    self::rich((string) $cell)
                );
            }
            $html .= '</tr>';
        }

        $this->emit($html . '</tbody></table>');
        $this->Ln(1);
    }

    /** @param array<string,mixed> $block */
    private function checklist(array $block): void
    {
        $html = '<table cellpadding="3" border="0">';

        foreach ((array) $block['items'] as $index => $item) {
            $state = (string) ($item['state'] ?? Doc::PENDING);
            $shade = $index % 2 === 1 ? ' background-color:#f8fafc;' : '';

            [$glyph, $colour] = self::glyph($state);

            $body = '<b>' . self::e((string) ($item['label'] ?? '')) . '</b>';

            if (trim((string) ($item['help'] ?? '')) !== '') {
                $body .= '<br/><span style="color:#7b8794;font-size:7.5pt;">'
                       . self::e((string) $item['help']) . '</span>';
            }

            if (trim((string) ($item['answer'] ?? '')) !== '') {
                $body .= '<br/><span style="font-size:8pt;">'
                       . self::e((string) $item['answer']) . '</span>';
            }

            if (trim((string) ($item['meta'] ?? '')) !== '') {
                $body .= '<br/><span style="color:#98a2b3;font-size:7pt;">'
                       . self::e((string) $item['meta']) . '</span>';
            }

            $html .= sprintf(
                '<tr><td width="6%%" style="color:%s;font-size:11pt;%s">%s</td>'
                . '<td width="8%%" style="color:#7b8794;font-size:8pt;%s">%s</td>'
                . '<td width="86%%" style="font-size:8.5pt;%s">%s</td></tr>',
                $colour,
                $shade,
                $glyph,
                $shade,
                self::e((string) ($item['number'] ?? '')),
                $shade,
                $body
            );
        }

        $this->emit($html . '</table>');
        $this->Ln(1);
    }

    /**
     * The four states, as a glyph and a colour.
     *
     * `⊘` for skipped and `✓` for done are the notation the SOP plugin already
     * writes into its completion followups, so a printed run and the ticket it
     * came from read the same way. Both need a Unicode font — see the class
     * comment.
     *
     * @return array{0:string,1:string}
     */
    private static function glyph(string $state): array
    {
        return match ($state) {
            Doc::DONE    => ['✓', '#2f7a4d'],
            Doc::SKIPPED => ['⊘', '#a06a1b'],
            Doc::NA      => ['·', '#b0b8c4'],
            default      => ['□', '#98a2b3'],
        };
    }

    /** @param array<string,mixed> $block */
    private function bulletList(array $block): void
    {
        $html = '<table cellpadding="1" border="0">';
        foreach ((array) $block['items'] as $item) {
            $html .= '<tr><td width="4%" style="color:' . self::e($this->brand->accent) . ';">•</td>'
                   . '<td width="96%" style="font-size:9pt;">' . self::rich((string) $item) . '</td></tr>';
        }
        $this->emit($html . '</table>');
        $this->Ln(1);
    }

    /** @param array<string,mixed> $block */
    private function timelineBlock(array $block): void
    {
        foreach ((array) $block['entries'] as $entry) {
            $head = trim(implode('  ·  ', array_filter([
                (string) ($entry['when'] ?? ''),
                (string) ($entry['who'] ?? ''),
                (string) ($entry['kind'] ?? ''),
            ])));

            $html = '<p style="font-size:7.5pt;color:#7b8794;margin:0;">' . self::e($head) . '</p>';

            if (trim((string) ($entry['body'] ?? '')) !== '') {
                $html .= '<p style="font-size:8.5pt;margin:0;">'
                       . self::rich((string) $entry['body']) . '</p>';
            }

            $this->emit($html);
            $this->Ln(1.5);
        }
    }

    /** @param array<string,mixed> $block */
    private function meterBar(array $block): void
    {
        $percent = (float) $block['percent'];
        $label   = self::plain((string) $block['label']);
        $caption = self::plain((string) $block['caption']);

        if ($this->remaining() < 16.0) {
            $this->AddPage();
        }

        $this->SetX(self::MARGIN_X);
        $this->SetFont(self::FONT, 'B', 8.5);
        $this->SetTextColor(...self::rgb('#1f2733'));
        $this->Cell(0, 5, $label, 0, 0, 'L');
        $this->Cell(0, 5, number_format($percent, 1) . '%', 0, 1, 'R');

        $y     = $this->GetY() + 0.5;
        $width = $this->getPageWidth() - (self::MARGIN_X * 2);

        $this->SetFillColor(...self::rgb('#e8edf3'));
        $this->Rect(self::MARGIN_X, $y, $width, 2.6, 'F');

        if ($percent > 0) {
            $this->SetFillColor(...self::rgb($this->brand->accent));
            $this->Rect(self::MARGIN_X, $y, $width * ($percent / 100), 2.6, 'F');
        }

        $this->SetY($y + 3.4);

        if ($caption !== '') {
            $this->SetX(self::MARGIN_X);
            $this->SetFont(self::FONT, '', 7.5);
            $this->SetTextColor(...self::rgb('#7b8794'));
            $this->Cell(0, 4, $caption, 0, 1, 'L');
        }

        $this->SetTextColor(0, 0, 0);
        $this->Ln(1.5);
    }

    /** @param array<string,mixed> $block */
    private function noteBox(array $block): void
    {
        [$border, $fill, $ink] = match ((string) $block['tone']) {
            Doc::WARN   => ['#e8c56a', '#fdf8ec', '#7a5a12'],
            Doc::DANGER => ['#e0a3a3', '#fdf1f1', '#8a2b2b'],
            default     => ['#c9d6e4', '#f5f9fd', '#2c4a68'],
        };

        $body = trim((string) $block['title']) !== ''
            ? '<b>' . self::e((string) $block['title']) . '</b> ' . self::rich((string) $block['body'])
            : self::rich((string) $block['body']);

        $this->emit(sprintf(
            '<table cellpadding="4" border="0"><tr><td style="background-color:%s;'
            . 'border:0.2mm solid %s;color:%s;font-size:8.5pt;">%s</td></tr></table>',
            $fill,
            $border,
            $ink,
            $body
        ));
        $this->Ln(1);
    }

    // ------------------------------------------------------------- plumbing

    /**
     * Hand generated markup to TCPDF.
     *
     * Named `emit` rather than the obvious `writeHtml` because TCPDF already
     * has one: PHP method names are case-insensitive, so `writeHtml()` and
     * TCPDF's own `writeHTML()` are the same method, and declaring it private
     * here is a compile-time fatal that `php -l` cannot see — it only surfaces
     * when the class is loaded. Every state reset here is deliberate: writeHTML
     * leaves the font, the colour and the X position wherever the last tag left
     * them, so a table with a coloured header would tint the paragraph after it.
     */
    private function emit(string $html): void
    {
        $this->SetX(self::MARGIN_X);
        $this->SetFont(self::FONT, '', 9);
        $this->SetTextColor(0, 0, 0);
        $this->writeHTML($html, true, false, true, false, '');
    }

    /** Millimetres left before the automatic page break. */
    private function remaining(): float
    {
        return $this->getPageHeight() - self::MARGIN_BOTTOM - $this->GetY();
    }

    /**
     * Escape for TCPDF's HTML parser.
     *
     * Everything a contributor supplies is plain text and is treated as plain
     * text. A ticket title containing `<b>` must print `<b>`; a category named
     * `R&D` must print `R&D`. Without this the parser would take both as
     * markup, which is not a security hole here — the output is a file, not a
     * page — but is a document that lies about what the record says.
     */
    private static function e(string $text): string
    {
        return htmlspecialchars(self::plain($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The same, with newlines kept.
     *
     * A ticket description is the reason this exists: it arrives as text with
     * paragraphs in it, and collapsing those to one block makes a description
     * unreadable at exactly the length where reading it matters.
     */
    private static function rich(string $text): string
    {
        return nl2br(self::e($text));
    }

    /**
     * Strip what has no meaning on paper.
     *
     * Contributors pass plain text, but "plain text" out of a database can
     * still carry control characters, and a stray `\r` or a NUL renders as a
     * black box or silently truncates the string in TCPDF.
     */
    private static function plain(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    /**
     * Column widths that always total 100.
     *
     * A contributor's percentages are a hint. TCPDF lays a table out from the
     * widths it is given and does not normalise them, so widths summing to 90
     * produce a table that stops short of the margin and widths summing to 130
     * produce one that runs off the page. Rather than trusting or refusing
     * them, they are scaled — and an empty or wrong-length list divides evenly,
     * which is the sane default and the common case.
     *
     * @param array<int,int> $given
     * @return array<int,int>
     */
    private static function widths(array $given, int $columns): array
    {
        if ($columns < 1) {
            return [];
        }

        $given = array_values(array_map('intval', $given));

        if (count($given) !== $columns || array_sum($given) <= 0) {
            $even = (int) floor(100 / $columns);
            $out  = array_fill(0, $columns, $even);
            $out[$columns - 1] += 100 - ($even * $columns);

            return $out;
        }

        $total = array_sum($given);
        $out   = [];
        foreach ($given as $width) {
            $out[] = max(1, (int) round(($width / $total) * 100));
        }

        // Rounding can land on 99 or 101; the last column absorbs it.
        $out[$columns - 1] += 100 - array_sum($out);

        return $out;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [0, 0, 0];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
