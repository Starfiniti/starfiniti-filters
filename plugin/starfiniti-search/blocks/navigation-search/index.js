(() => {
  'use strict';
  const { registerBlockType } = window.wp.blocks;
  const { createElement } = window.wp.element;
  const { __ } = window.wp.i18n;
  registerBlockType('starfiniti/navigation-search', {
    edit: () => createElement('div', { className: 'components-placeholder' }, createElement('strong', {}, __('Starfiniti Navigation Search', 'starfiniti-search'))),
    save: () => null,
  });
})();
