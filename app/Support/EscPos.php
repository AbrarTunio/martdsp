<?php

namespace App\Support;

/**
 * Builds the byte stream a thermal printer actually understands.
 *
 * A receipt printer is not a printer in the Windows sense. It is a spool that
 * accepts ESC/POS — a handful of escape codes from the 1990s that turn bold
 * on, set the alignment, feed the paper, fire the cutter and pop the cash
 * drawer. Everything in between is plain text, one character per column, in a
 * fixed-width font. So the whole job here is two things: emit the right escape
 * codes, and lay text out against a known number of columns.
 *
 * Nothing in this class talks to a printer. It hands back a string of bytes,
 * which makes the layout testable without any hardware in the room.
 */
final class EscPos
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    /** Alignment argument for `ESC a n`. */
    private const ALIGNMENTS = ['left' => 0, 'center' => 1, 'right' => 2];

    private string $buffer = '';

    /**
     * @param  int  $columns  how many characters fit across the roll
     */
    public function __construct(private readonly int $columns = 42) {}

    /**
     * Wake the printer and clear whatever the last job left set.
     *
     * Code page 0 is PC437, which every printer on the market supports and
     * which covers the ASCII a receipt is reduced to.
     */
    public function init(): self
    {
        return $this->raw(self::ESC.'@')->raw(self::ESC.'t'.chr(0));
    }

    public function align(string $where): self
    {
        return $this->raw(self::ESC.'a'.chr(self::ALIGNMENTS[$where] ?? 0));
    }

    public function bold(bool $on = true): self
    {
        return $this->raw(self::ESC.'E'.chr($on ? 1 : 0));
    }

    public function underline(bool $on = true): self
    {
        return $this->raw(self::ESC.'-'.chr($on ? 1 : 0));
    }

    /**
     * Character size as a multiple of normal, 1 to 8 each way.
     */
    public function size(int $width = 1, int $height = 1): self
    {
        $width = max(1, min(8, $width));
        $height = max(1, min(8, $height));

        return $this->raw(self::GS.'!'.chr((($width - 1) << 4) | ($height - 1)));
    }

    /**
     * Text as-is, with no line break. Non-printable characters are stripped.
     */
    public function text(string $text): self
    {
        return $this->raw(self::ascii($text));
    }

    public function line(string $text = ''): self
    {
        return $this->text($text)->raw("\n");
    }

    /**
     * A line that is too long to fit, broken on spaces across as many lines
     * as it takes. Used for product names and addresses.
     */
    public function wrapped(string $text, int $indent = 0): self
    {
        $width = max(8, $this->columns - $indent);
        $pad = str_repeat(' ', $indent);

        foreach (explode("\n", wordwrap(self::ascii($text), $width, "\n", true)) as $row) {
            $this->line($pad.$row);
        }

        return $this;
    }

    /**
     * A label on the left and a figure on the right, filling the gap between
     * them. This is the shape of almost every line on a receipt.
     */
    public function row(string $left, string $right, string $fill = ' '): self
    {
        $left = self::ascii($left);
        $right = self::ascii($right);

        $room = $this->columns - mb_strlen($right) - 1;

        if ($room < 1) {
            return $this->line($right);
        }

        if (mb_strlen($left) > $room) {
            $left = mb_substr($left, 0, $room);
        }

        $gap = $this->columns - mb_strlen($left) - mb_strlen($right);

        return $this->line($left.str_repeat($fill, max(1, $gap)).$right);
    }

    /**
     * A full-width rule. Dashes by default, equals signs for a heavier one.
     */
    public function rule(string $char = '-'): self
    {
        return $this->line(str_repeat($char, $this->columns));
    }

    /**
     * One line centred by padding rather than by the printer, so it can sit
     * inside a block that is already left-aligned.
     */
    public function centred(string $text): self
    {
        $text = self::ascii($text);
        $pad = max(0, intdiv($this->columns - mb_strlen($text), 2));

        return $this->line(str_repeat(' ', $pad).$text);
    }

    public function feed(int $lines = 1): self
    {
        return $lines > 0 ? $this->raw(self::ESC.'d'.chr(min(255, $lines))) : $this;
    }

    /**
     * Feed the paper clear of the head, then cut.
     *
     * The feed is not decoration: the cutter sits a couple of centimetres
     * past the print head, so without it the cut lands in the middle of the
     * last few lines.
     */
    public function cut(int $feedLines = 4): self
    {
        return $this->feed($feedLines)->raw(self::GS.'V'.chr(1));
    }

    /**
     * Pop the cash drawer.
     *
     * The drawer is a solenoid on an RJ11 socket at the back of the printer,
     * and this is the pulse that fires it. Pin 0 is the common wiring; some
     * tills use pin 1. The two timings are on-time and off-time in 2 ms
     * units — 60 ms on is enough for every drawer we have seen and short
     * enough not to cook the coil.
     */
    public function pulse(int $pin = 0): self
    {
        return $this->raw(self::ESC.'p'.chr($pin === 1 ? 1 : 0).chr(30).chr(255));
    }

    /**
     * Reset everything the receipt turned on, so the next job starts clean.
     */
    public function reset(): self
    {
        return $this->bold(false)->underline(false)->size()->align('left');
    }

    public function raw(string $bytes): self
    {
        $this->buffer .= $bytes;

        return $this;
    }

    public function bytes(): string
    {
        return $this->buffer;
    }

    public function width(): int
    {
        return $this->columns;
    }

    /**
     * Reduce text to what a thermal printer can actually form.
     *
     * The print head has one fixed font and no idea what Urdu is, so
     * everything outside ASCII is either mapped to its nearest plain
     * equivalent or dropped. Dropping is deliberate: a receipt with a gap in
     * it is easier to read than one full of question marks, and the caller
     * always has the English name to fall back on.
     */
    public static function ascii(string $text): string
    {
        $text = strtr($text, [
            '–' => '-', '—' => '-', '−' => '-',
            '‘' => "'", '’' => "'", '“' => '"', '”' => '"',
            '…' => '...', '·' => '-', '×' => 'x',
            '₨' => 'Rs.', '﷼' => 'Rs.', '€' => 'EUR', '£' => 'GBP',
            "\r" => '',
        ]);

        return preg_replace('/[^\x20-\x7E\n]/u', '', $text) ?? '';
    }
}
