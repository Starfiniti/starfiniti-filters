(() => {
  'use strict';
  const { registerBlockType } = window.wp.blocks;
  const { createElement } = window.wp.element;
  const { __ } = window.wp.i18n;

  registerBlockType('starfiniti/search', {
    edit: () => createElement(
      'div',
      { className: 'components-placeholder' },
      createElement('strong', {}, __('Starfiniti Product Search', 'starfiniti-search')),
      createElement('p', {}, __('The accessible search interface is rendered on the storefront.', 'starfiniti-search'))
    ),
    save: () => null,
  });
})();
