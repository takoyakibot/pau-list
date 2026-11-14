import { test } from 'node:test';
import assert from 'node:assert/strict';
import { countChars } from '../../resources/js/lib/text.js';

test('countChars', () => {
  assert.equal(countChars('a\r\nb'), 3);
  assert.equal(countChars('😀'), 1);
  assert.equal(countChars('👨‍👩‍👧'), 5);
  assert.equal(countChars(''), 0);
});
