import { test } from 'node:test';
import assert from 'node:assert/strict';
import { countChars, isOverLimit } from '../../resources/js/lib/text.js';

test('countChars', () => {
  assert.equal(countChars('a\r\nb'), 3);
  assert.equal(countChars('😀'), 1);
  assert.equal(countChars('👨‍👩‍👧'), 5);
  assert.equal(countChars(''), 0);
});

test('isOverLimit counts code points, not UTF-16 units', () => {
  assert.equal(isOverLimit('😀'.repeat(150), 150), false);
  assert.equal(isOverLimit('😀'.repeat(151), 150), true);
  assert.equal(isOverLimit('a\r\n'.repeat(10), 20), false);
  assert.equal(isOverLimit('', 0), false);
  assert.equal(isOverLimit(null, 0), false);
});
