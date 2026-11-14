import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatPublishedDate, watchUrl } from '../../resources/js/lib/format.js';

test('published date is shown in Japan time as n月j日', () => {
  assert.equal(formatPublishedDate('2026-01-01T15:30:00Z'), '1月2日');
  assert.equal(formatPublishedDate('2026-01-01T14:59:59Z'), '1月1日');
  assert.equal(formatPublishedDate('2025-12-09T00:00:00Z'), '12月9日');
});
test('missing or broken dates give an empty label', () => {
  assert.equal(formatPublishedDate(null), '');
  assert.equal(formatPublishedDate(''), '');
  assert.equal(formatPublishedDate('not a date'), '');
  assert.equal(formatPublishedDate(123), '');
});
test('watchUrl builds a watch URL only for an 11-char id', () => {
  assert.equal(watchUrl('aaaaaaaaaaa'), 'https://www.youtube.com/watch?v=aaaaaaaaaaa');
  assert.equal(watchUrl('bad id'), '');
  assert.equal(watchUrl(undefined), '');
});
