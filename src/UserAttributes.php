<?php

namespace FoskyM\CustomLevels;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Flarum\Api\Serializer\UserSerializer;
use FoskyM\CustomLevels\Model\Level;

class UserAttributes
{
    protected $settings;
    protected $extensionManager;

    public function __construct(
        SettingsRepositoryInterface $settings,
        ExtensionManager $extensionManager
    ) {
        $this->settings = $settings;
        $this->extensionManager = $extensionManager;
    }

    public function __invoke(UserSerializer $serializer, User $user, array $attributes): array
    {
        $expLevel    = 0;       // 显示用：等级名称（原实现用 name）
        $expTotal    = 0;       // 当前经验
        $expPercent  = 0;       // 本级区间百分比（0–100）
        $expNext     = '';      // 下一等级名称
        $expNextNeed = 0;       // 距离下一等级还需多少经验（>=0）

        try {
            $expTotal = (int) ($user->exp ?? 0);

            // 维持原逻辑的排序/选取方式，避免牵动其它代码
            $levels = Level::orderBy('min_exp_required', 'desc')->get();

            if ($levels->count() === 0) {
                // 没有任何等级配置，给出“空”但安全的返回
                $attributes += [
                    'expLevel'    => -1,
                    'expTotal'    => $expTotal,
                    'expPercent'  => -1,
                    'expNext'     => '',
                    'expNextNeed' => -1,
                ];
                return $attributes;
            }

            // 当前等级：<= 当前经验 的最大阈值
            $level = $levels->where('min_exp_required', '<=', $expTotal)
                            ->sortByDesc('min_exp_required')
                            ->first();

            if ($level) {
                $expLevel = $level->name;
            }

            // 下一等级：> 当前经验 的最小阈值
            $levelNext = $levels->where('min_exp_required', '>', $expTotal)
                                ->sortBy('min_exp_required')
                                ->first();

            if ($levelNext) {
                $lower = $level ? (int) $level->min_exp_required : 0;
                $upper = (int) $levelNext->min_exp_required;
                $den   = $upper - $lower;

                if ($den > 0) {
                    // 关键修复：按分母是否>0判断，首档 lower=0 也能正常计算
                    $ratio = ($expTotal - $lower) / $den;                 // 0–1
                    // 夹取并转为 0–100 的百分比（整数）
                    $expPercent = (int) round(max(0, min(1, $ratio)) * 100);
                } else {
                    // 配置异常（上下界相等/反向），兜底为 100%
                    $expPercent = 100;
                }

                $expNext     = $levelNext->name;
                $expNextNeed = max(0, $upper - $expTotal);
            } else {
                // 已达最高档
                $top = $levels->first(); // 注意：此时 $levels 为降序，first() 即最高阈值
                if ($top) {
                    $expNext     = $top->name;
                    $expNextNeed = max(0, (int) $top->min_exp_required - $expTotal);
                }
                if ($expNextNeed === 0) {
                    // 顶级：显示满格
                    $expNext     = '-';
                    $expPercent  = 100;
                }
            }
        } catch (\Exception $e) {
            // 与前端的错误判定协议保持一致：出现异常给 -1
            $expLevel    = -1;
            $expTotal    = -1;
            $expPercent  = -1;
            $expNext     = '';
            $expNextNeed = -1;
        }

        $attributes += [
            'expLevel'    => $expLevel,
            'expTotal'    => $expTotal,
            'expPercent'  => $expPercent,   // 明确约定：0–100
            'expNext'     => $expNext,
            'expNextNeed' => $expNextNeed,
        ];

        return $attributes;
    }
}
