import { existsSync, readFileSync, mkdirSync, renameSync, writeFileSync } from 'fs';
import { join, dirname } from 'path';

import _glob from 'glob';
const { sync: glob } = _glob;
import { compareVersions } from 'compare-versions';
import { parse as parseFont } from 'opentype.js';

import { ttcUnpack } from './ttc_unpack.js';
import { FONTS_STORAGE } from './config.js';

const INDEX_FILE = join(FONTS_STORAGE, 'index.json');
const INDEX = existsSync(INDEX_FILE) ? JSON.parse(readFileSync(INDEX_FILE)) : {};

function cleanVersion(version) {
    let match = /[Vv]ersion\s*([\d\.]+)/.exec(version);
    if (match) {
        return match[1];
    }
    match = /^([\d\.]+)[a-zA-Z]\w+$/.exec(version);
    if (match) {
        return match[1];
    }
    return version.trim();
}

function defaultLocale(names, testKey = 'fontFamily') {
    for (const l of ['en', 'zh', 'ja']) {
        if (names[testKey][l]) {
            return l;
        }
    }

    const l = Object.keys(names[testKey])[0];
    console.warn('\tAbnormal default locale, fallback to ' + l);
    return l;
}

function processFont(data, force) {
    const font = parseFont(data.buffer), names = font.names;
    if (!names.fontFamily) {
        throw 'Can\'t parse name table!';
    }

    const locale = defaultLocale(names);
    const lattr = (key) => {
        if (!names[key]) {
            console.warn(`\tUnable to read ${key}, fallback to empty`);
            return '';
        }
        if (names[key][locale]) {
            return names[key][locale].trim();
        }
        const fallback = Object.keys(names[key])[0];
        console.warn(`\tUnable to read ${key}.${locale}, fallback to ${fallback}`);
        return names[key][fallback].trim();
    };

    let storage = join(FONTS_STORAGE, lattr('fontFamily'));
    mkdirSync(storage, {
        recursive: true,
    });

    storage = join(storage, lattr('fontSubfamily'));
    const install = () => {
        console.info('\t=> Store ' + storage);
        writeFileSync(`${storage}.ttf`, data);
        writeFileSync(`${storage}.json`, JSON.stringify(names, undefined, '\t'));
    };

    if (!existsSync(storage + '.json')) {
        install();
    } else if (force) {
        console.warn('\tforce install');
        install();
    } else {
        const meta = JSON.parse(readFileSync(storage + '.json'));

        const current_raw = lattr('version'), existing_raw = meta.version[defaultLocale(meta, 'version')];
        if (current_raw == existing_raw) {
            // Exactly same
        } else {
            const current = cleanVersion(current_raw), existing = cleanVersion(existing_raw);
            if (current == existing) {
                console.warn('\tsame version after clean: ' + current_raw + ' == ' + existing_raw);
            } else {
                try {
                    if (compareVersions(current, existing) <= 0) {
                        console.warn('\tversion smaller: ' + current_raw + ' <= ' + existing_raw);
                    } else {
                        console.warn('\tversion greater, overwriting: ' + current_raw + ' > ' + existing_raw);
                        install();
                    }
                } catch {
                    // debugger;
                    throw 'version compare error: ' + current_raw + ' / ' + existing_raw;
                }
            }
        }
    }

    storage = `${lattr('fontFamily')}/${lattr('fontSubfamily')}.ttf`;
    for (const f in names.fontFamily) {
        const family = names.fontFamily[f], subFamily = names.fontSubfamily[f];

        if (!INDEX[family]) {
            INDEX[family] = {
                [subFamily]: storage,
            };
            continue;
        }

        const set = INDEX[family];
        if (set[subFamily] && set[subFamily] !== storage) {
            console.warn(`\tindex replaced: ${family}/${subFamily}, ${set[subFamily]} to ${storage}`);
        }
        set[subFamily] = storage;
    }
}

for (let f of glob('source/**/*', { dot: true, nocase: true, nodir: true })) {
    if (!/\.(ttf|otf|ttc)$/i.test(f)) {
        console.info('Skip: ' + f);
        continue;
    }
    const move = (target) => {
        const mv = join(target, f.replace(/[^\\/]+[\\/]/, ''));
        mkdirSync(dirname(mv), { recursive: true });
        renameSync(f, mv);
    };

    console.info('Processing: ' + f);
    try {
        const force = f.startsWith('source/_reindex') ||
            f.startsWith('source/方正字加') ||
            f.startsWith('source/汉仪官方');

        const buffer = readFileSync(f);
        if (!ttcUnpack(buffer, (ttf, i) => {
            console.info('  Unpacked #' + i);
            processFont(ttf, force)
        })) {
            processFont(buffer, force);
        }
        move('source.done');
    } catch (e) {
        console.error(e);
        move('source.fail');
    }
}

console.info('Writing index.json to ' + INDEX_FILE);
writeFileSync(INDEX_FILE, JSON.stringify(INDEX, undefined, '\t'));
