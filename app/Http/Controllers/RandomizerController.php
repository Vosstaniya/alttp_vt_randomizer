<?php

namespace ALttP\Http\Controllers;

use ALttP\Enemizer;
use ALttP\EntranceRandomizer;
use ALttP\Http\Requests\CreateRandomizedGame;
use ALttP\Jobs\SendPatchToDisk;
use ALttP\Randomizer;
use ALttP\Rom;
use ALttP\Support\WorldCollection;
use ALttP\World;
use Exception;

class RandomizerController extends Controller
{
    public function generateSeed(CreateRandomizedGame $request)
    {
        if ($request->has('lang')) {
            app()->setLocale($request->input('lang'));
        }

        try {
            $payload = $this->prepSeed($request);
            $payload['seed']->save();
            SendPatchToDisk::dispatch($payload['seed']);

            $return_payload = array_except($payload, [
                'seed',
                'spoiler.meta.crystals_ganon',
                'spoiler.meta.crystals_tower',
            ]);

            if ($payload['spoiler']['meta']['tournament'] ?? false) {
                if ($payload['spoiler']['meta']['spoilers'] ?? false) {
                    $return_payload = array_except($return_payload, [
                        'spoiler.playthrough'
                    ]);
                } elseif ($payload['spoiler']['meta']['spoilers_ongen'] ?? false) {
                    $return_payload = array_except($return_payload, [
                        'spoiler.playthrough',
                    ]);
                } else {
                    $return_payload['spoiler'] = array_except(array_only($return_payload['spoiler'], [
                        'meta',
                    ]), ['meta.seed']);
                }
            }

            $cached_payload = $return_payload;
            if ($payload['spoiler']['meta']['spoilers_ongen'] ?? false) {
                // ensure that the cache doesn't have the spoiler, but the original return_payload still does
                $cached_payload['spoiler'] = array_except(array_only($return_payload['spoiler'], [
                    'meta',
                ]), ['meta.seed']);
            }
            $save_data = json_encode(array_except($cached_payload, [
                'current_rom_hash',
            ]));
            cache(['hash.' . $payload['hash'] => $save_data], now()->addDays(7));

            return json_encode($return_payload);
        } catch (Exception $exception) {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($exception);
            }

            return response($exception->getMessage(), 409);
        }
    }

    protected function prepSeed(CreateRandomizedGame $request)
    {
        $crystals_ganon = $request->input('crystals.ganon', '7');
        $crystals_ganon = $crystals_ganon === 'random' ? get_random_int(0, 7) : $crystals_ganon;
        $crystals_tower = $request->input('crystals.tower', '7');
        $crystals_tower = $crystals_tower === 'random' ? get_random_int(0, 7) : $crystals_tower;
        $logic = [
            'none' => 'NoGlitches',
            'overworld_glitches' => 'OverworldGlitches',
            'major_glitches' => 'MajorGlitches',
            'no_logic' => 'None',
        ][$request->input('glitches', 'none')];

        // quick fix for CC and Basic
        if ($request->input('item.pool', 'normal') === 'crowd_control') {
            $request->merge(['item_placement' => 'advanced']);
        }

        $world = World::factory($request->input('mode', 'standard'), [
            'itemPlacement' => $request->input('item_placement', 'basic'),
            'dungeonItems' => $request->input('dungeon_items', 'standard'),
            'accessibility' => $request->input('accessibility', 'items'),
            'goal' => $request->input('goal', 'ganon'),
            'crystals.ganon' => $crystals_ganon,
            'crystals.tower' => $crystals_tower,
            'entrances' => $request->input('entrances', 'none'),
            'mode.weapons' => $request->input('weapons', 'randomized'),
            'tournament' => $request->input('tournament', false),
            'spoilers' => $request->input('spoilers', false),
            'spoilers_ongen' => $request->input('spoilers_ongen', false),
            'spoil.Hints' => $request->input('hints', 'on'),
            'logic' => $logic,
            'item.pool' => $request->input('item.pool', 'normal'),
            'item.functionality' => $request->input('item.functionality', 'normal'),
            'enemizer.bossShuffle' => $request->input('enemizer.boss_shuffle', 'none'),
            'enemizer.enemyShuffle' => $request->input('enemizer.enemy_shuffle', 'none'),
            'enemizer.enemyDamage' => $request->input('enemizer.enemy_damage', 'default'),
            'enemizer.enemyHealth' => $request->input('enemizer.enemy_health', 'default'),
        ]);

        $rom = new Rom(env('ENEMIZER_BASE', null));
        $rom->applyPatchFile(public_path('js/base2current.json'));

        if ($world->config('entrances') !== 'none') {
            $rand = new EntranceRandomizer([$world]);
        } else {
            $rand = new Randomizer([$world]);
        }

        $rand->randomize();
        $world->writeToRom($rom, true);

        // E.R. is responsible for verifying winnability of itself
        if ($world->config('entrances') === 'none') {
            $worlds = new WorldCollection($rand->getWorlds());

            if (!$worlds->isWinnable()) {
                throw new Exception('Game Unwinnable');
            }
        }

        $spoiler = $world->getSpoiler([
            'entry_crystals_ganon' => $request->input('crystals.ganon', '7'),
            'entry_crystals_tower' => $request->input('crystals.tower', '7'),
            'worlds' => 1,
        ]);

        if ($world->isEnemized()) {
            $patch = $rom->getWriteLog();
            $en = new Enemizer($world, $patch);
            $en->randomize();
            $en->writeToRom($rom);
        }

        $rom->setTournamentType($request->input('spoilers', false) ? 'none' : 'standard');
        $rom->rummageTable();
        $patch = $rom->getWriteLog();

        if ($world->isEnemized()) {
            $world->updateSeedRecordPatch($patch);
        }

        return [
            'logic' => $world->config('logic'),
            'patch' => patch_merge_minify($patch),
            'spoiler' => $spoiler,
            'hash' => $world->getSeedRecord()->hash,
            'generated' => $world->getSeedRecord()->created_at ? $world->getSeedRecord()->created_at->toIso8601String() : now()->toIso8601String(),
            'seed' => $world->getSeedRecord(),
            'size' => $spoiler['meta']['size'] ?? 2,
            'current_rom_hash' => Rom::HASH,
        ];
    }
}
