L.TileLayer.Canvas = L.TileLayer.extend({
  
  options: {
    crossOrigin: true
  },
  
  createTile: function(coords, done) {
    const tile = document.createElement('canvas');
    const tileSize = this.getTileSize();
    
    tile.width = tileSize.x;
    tile.height = tileSize.y;
    
    const ctx = tile.getContext('2d', {
      alpha: false,  // ✅ Wyłącz alpha channel
      desynchronized: true  // ✅ Async rendering
    });
    
    const img = new Image();
    img.crossOrigin = 'Anonymous';
    
    img.onload = () => {
      // ✅ Rysuj z 1px overlap (eliminuje gaps)
      ctx.drawImage(img, -1, -1, tileSize.x + 2, tileSize.y + 2);
      done(null, tile);
    };
    
    img.onerror = () => {
      done(new Error('Tile load failed'), tile);
    };
    
    img.src = this.getTileUrl(coords);
    
    return tile;
  }
  
});

L.tileLayer.canvas = function(url, options) {
  return new L.TileLayer.Canvas(url, options);
};

console.log('✅ Canvas Tile Layer loaded');