import { test } from 'node:test';
import assert from 'node:assert/strict';
import { filterVideos, normalizeText } from '../../resources/js/lib/filter.js';

const vs = [{ id: '1', title: 'abc 配信' }, { id: '2', title: 'abc 雑談' }, { id: '3', title: 'xyz 配信' }];

test('full-width and uppercase match', () => {
  assert.deepEqual(filterVideos(vs, 'ＡＢＣ').map(v => v.id), ['1', '2']);
  assert.equal(normalizeText('  ＡＢＣ '), 'abc');
});
test('all words must match', () => { assert.deepEqual(filterVideos(vs, 'a 配信').map(v => v.id), ['1']); });
test('empty query returns all', () => { assert.equal(filterVideos(vs, '  ').length, 3); });
