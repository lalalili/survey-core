<?php

namespace Lalalili\SurveyCore\Enums;

enum SurveyFieldType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case Select = 'select';
    case Rating = 'rating';
    case Number = 'number';
    case Nps = 'nps';
    case MatrixSingle = 'matrix_single';
    case MatrixMulti = 'matrix_multi';
    case Ranking = 'ranking';
    case FileUpload = 'file_upload';
    case Signature = 'signature';
    case Address = 'address';
    case CascadeSelect = 'cascade_select';
    case Email = 'email';
    case Phone = 'phone';
    case Date = 'date';
    case Time = 'time';
    case LinearScale = 'linear_scale';
    case ConstantSum = 'constant_sum';
    case Hidden = 'hidden';
    case SectionTitle = 'section_title';
    case DescriptionBlock = 'description_block';
    case Divider = 'divider';
    case QuoteBlock = 'quote_block';

    public function label(): string
    {
        return match ($this) {
            self::ShortText => '單行文字',
            self::LongText => '多行文字',
            self::SingleChoice => '單選',
            self::MultipleChoice => '多選',
            self::Select => '下拉選單',
            self::Rating => '評分',
            self::Number => '數字',
            self::Nps => 'NPS',
            self::MatrixSingle => '矩陣單選',
            self::MatrixMulti => '矩陣複選',
            self::Ranking => '排序',
            self::FileUpload => '檔案上傳',
            self::Signature => '簽名',
            self::Address => '地址',
            self::CascadeSelect => '巢狀選擇',
            self::Email => 'Email',
            self::Phone => '電話',
            self::Date => '日期',
            self::Time => '時間',
            self::LinearScale => '數字滑桿',
            self::ConstantSum => '總計題',
            self::Hidden => '個性化欄位',
            self::SectionTitle => '區段標題',
            self::DescriptionBlock => '說明文字',
            self::Divider => '分隔線',
            self::QuoteBlock => '引言',
        };
    }

    public function requiresOptions(): bool
    {
        return in_array($this, [self::SingleChoice, self::MultipleChoice, self::Select, self::Ranking, self::ConstantSum], true);
    }

    public function isAlwaysHidden(): bool
    {
        return $this === self::Hidden;
    }

    public function supportsMultipleValues(): bool
    {
        return in_array($this, [self::MultipleChoice, self::MatrixMulti, self::Ranking, self::ConstantSum], true);
    }

    public function isContentBlock(): bool
    {
        return in_array($this, [self::SectionTitle, self::DescriptionBlock, self::Divider, self::QuoteBlock], true);
    }
}
