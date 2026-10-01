<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/** Contact Form 7 submissions stored by the CFDB7 plugin ({p}db7_forms) -> form_submissions (replaced on every run). */
class FormsStep extends AbstractStep
{
    public function key(): string
    {
        return 'extras.forms';
    }

    public function section(): string
    {
        return 'extras';
    }

    protected function clear(): void
    {
        DB::table('form_submissions')->where('data->source', 'wordpress')->delete();
    }

    protected function import(): void
    {
        $forms = $this->wp->table('posts')->where('post_type', 'wpcf7_contact_form')->pluck('post_title', 'ID')->all();
        DB::table('form_submissions')->where('data->source', 'wordpress')->delete();
        $rows = [];
        $count = 0;
        $total = 0;
        foreach ($this->wp->table('db7_forms')->orderBy('form_id')->cursor() as $f) {
            $total++;
            $values = WordPressSource::unserialize($f->form_value);
            if (! is_array($values)) {
                continue;
            }
            $read = ($values['cfdb7_status'] ?? '') === 'read';
            $clean = [];
            foreach ($values as $k => $v) {
                if (in_array($k, ['cfdb7_status', 'hcap_fst_token', 'hcaptcha-widget-id', 'h-captcha-response', 'g-recaptcha-response'], true) || str_starts_with($k, '_wpcf7')) {
                    continue;
                }
                $clean[$k] = is_array($v) ? implode(', ', $v) : Formatter::decode((string) $v);
            }
            $name = $clean['your-name'] ?? trim(($clean['first-name'] ?? '').' '.($clean['last-name'] ?? '')) ?: null;
            $formTitle = $forms[$f->form_post_id] ?? 'Contact form';
            $rows[] = [
                'form' => Str::slug($formTitle) ?: 'contact',
                'name' => $name ? Str::limit($name, 250, '') : null,
                'email' => isset($clean['your-email']) ? Str::limit(strtolower($clean['your-email']), 250, '') : null,
                'phone' => isset($clean['your-phone-number']) ? Str::limit($clean['your-phone-number'], 250, '') : ($clean['your-phone'] ?? null),
                'subject' => isset($clean['your-subject']) ? Str::limit($clean['your-subject'], 250, '') : null,
                'message' => $clean['your-message'] ?? null,
                'data' => json_encode(['source' => 'wordpress', 'form_title' => $formTitle, 'fields' => $clean], JSON_UNESCAPED_UNICODE),
                'ip_address' => null,
                'read_at' => $read ? $f->form_date : null,
                'created_at' => $f->form_date,
                'updated_at' => $f->form_date,
            ];
            if (count($rows) >= 200) {
                $this->ctx->insert('form_submissions', $rows, 200);
                $count += count($rows);
                $rows = [];
            }
        }
        $this->ctx->insert('form_submissions', $rows, 200);
        $this->ctx->count('Contact form submissions', $total, $count + count($rows));
    }
}
