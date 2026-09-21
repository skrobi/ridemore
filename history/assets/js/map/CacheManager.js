/* ============================================================================
   CACHE MANAGER - Universal caching system
   IndexedDB + localStorage + memory
   ============================================================================ */

class CacheManager {
  static instance = null;
  
  constructor() {
    if (CacheManager.instance) {
      return CacheManager.instance;
    }
    
    this.memoryCache = new Map();
    this.dbName = 'KoronaCache';
    this.version = 1;
    this.db = null;
    this.initDB();
    
    CacheManager.instance = this;
  }
  
  static getInstance() {
    if (!CacheManager.instance) {
      CacheManager.instance = new CacheManager();
    }
    return CacheManager.instance;
  }
  
  // ========================================================================
  // INDEXEDDB INITIALIZATION
  // ========================================================================
  
  async initDB() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(this.dbName, this.version);
      
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        this.db = request.result;
        resolve(this.db);
      };
      
      request.onupgradeneeded = (event) => {
        const db = event.target.result;
        
        // Store dla geometrii
        if (!db.objectStoreNames.contains('geometry')) {
          const geometryStore = db.createObjectStore('geometry', { keyPath: 'id' });
          geometryStore.createIndex('type', 'type', { unique: false });
          geometryStore.createIndex('timestamp', 'timestamp', { unique: false });
        }
        
        // Store dla metadata
        if (!db.objectStoreNames.contains('metadata')) {
          const metadataStore = db.createObjectStore('metadata', { keyPath: 'id' });
          metadataStore.createIndex('type', 'type', { unique: false });
          metadataStore.createIndex('timestamp', 'timestamp', { unique: false });
        }
      };
    });
  }
  
  // ========================================================================
  // GET DATA (3-level cache)
  // ========================================================================
  
  async get(type, id, options = {}) {
    const cacheKey = `${type}_${id}`;
    const ttl = options.ttl || this.getTTL(type);
    
    // 1. Memory cache
    if (this.memoryCache.has(cacheKey)) {
      const cached = this.memoryCache.get(cacheKey);
      if (!this.isExpired(cached, ttl)) {
        return cached.data;
      }
      this.memoryCache.delete(cacheKey);
    }
    
    // 2. IndexedDB
    const stored = await this.getFromDB(type, id);
    if (stored && !this.isExpired(stored, ttl)) {
      this.memoryCache.set(cacheKey, stored);
      return stored.data;
    }
    
    // 3. Not found
    return null;
  }
  
  // ========================================================================
  // SAVE DATA
  // ========================================================================
  
  async set(type, id, data, options = {}) {
    const cacheKey = `${type}_${id}`;
    const entry = {
      id: `${type}_${id}`,
      type,
      itemId: id,
      data,
      timestamp: Date.now(),
      ...options
    };
    
    // Memory
    this.memoryCache.set(cacheKey, entry);
    
    // IndexedDB
    await this.saveToDB(type, entry);
    
    // localStorage (tylko IDs dla quick lookup)
    this.updateLocalStorageIndex(type, id);
  }
  
  // ========================================================================
  // INDEXEDDB OPERATIONS
  // ========================================================================
  
  async getFromDB(type, id) {
    if (!this.db) await this.initDB();
    
    return new Promise((resolve, reject) => {
      const storeName = this.getStoreName(type);
      const transaction = this.db.transaction([storeName], 'readonly');
      const store = transaction.objectStore(storeName);
      const request = store.get(`${type}_${id}`);
      
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }
  
  async saveToDB(type, entry) {
    if (!this.db) await this.initDB();
    
    return new Promise((resolve, reject) => {
      const storeName = this.getStoreName(type);
      const transaction = this.db.transaction([storeName], 'readwrite');
      const store = transaction.objectStore(storeName);
      const request = store.put(entry);
      
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }
  
  async deleteFromDB(type, id) {
    if (!this.db) await this.initDB();
    
    return new Promise((resolve, reject) => {
      const storeName = this.getStoreName(type);
      const transaction = this.db.transaction([storeName], 'readwrite');
      const store = transaction.objectStore(storeName);
      const request = store.delete(`${type}_${id}`);
      
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }
  
  // ========================================================================
  // LOCALSTORAGE OPERATIONS
  // ========================================================================
  
  updateLocalStorageIndex(type, id) {
    const key = `${type}_index`;
    let index = JSON.parse(localStorage.getItem(key) || '[]');
    
    if (!index.includes(id)) {
      index.push(id);
      // Keep only last 100
      if (index.length > 100) {
        index = index.slice(-100);
      }
      localStorage.setItem(key, JSON.stringify(index));
    }
  }
  
  getLocalStorageIndex(type) {
    const key = `${type}_index`;
    return JSON.parse(localStorage.getItem(key) || '[]');
  }
  
  // ========================================================================
  // BULK OPERATIONS
  // ========================================================================
  
  async getBulk(type, ids) {
    const results = [];
    for (const id of ids) {
      const data = await this.get(type, id);
      if (data) results.push(data);
    }
    return results;
  }
  
  async setBulk(type, items) {
    const promises = items.map(item => 
      this.set(type, item.id, item.data, item.options)
    );
    await Promise.all(promises);
  }
  
  // ========================================================================
  // CLEANUP
  // ========================================================================
  
  async cleanup(type = null) {
    if (!this.db) await this.initDB();
    
    const stores = type ? [this.getStoreName(type)] : ['geometry', 'metadata'];
    
    for (const storeName of stores) {
      const transaction = this.db.transaction([storeName], 'readwrite');
      const store = transaction.objectStore(storeName);
      const index = store.index('timestamp');
      const cutoff = Date.now() - (30 * 24 * 60 * 60 * 1000); // 30 days
      
      const request = index.openCursor();
      request.onsuccess = (event) => {
        const cursor = event.target.result;
        if (cursor) {
          if (cursor.value.timestamp < cutoff) {
            cursor.delete();
          }
          cursor.continue();
        }
      };
    }
  }
  
  async clear(type = null) {
    // Clear memory
    if (type) {
      for (const key of this.memoryCache.keys()) {
        if (key.startsWith(`${type}_`)) {
          this.memoryCache.delete(key);
        }
      }
    } else {
      this.memoryCache.clear();
    }
    
    // Clear IndexedDB
    if (!this.db) await this.initDB();
    
    const stores = type ? [this.getStoreName(type)] : ['geometry', 'metadata'];
    
    for (const storeName of stores) {
      const transaction = this.db.transaction([storeName], 'readwrite');
      const store = transaction.objectStore(storeName);
      await store.clear();
    }
    
    // Clear localStorage indexes
    if (type) {
      localStorage.removeItem(`${type}_index`);
    } else {
      const keys = Object.keys(localStorage).filter(k => k.endsWith('_index'));
      keys.forEach(k => localStorage.removeItem(k));
    }
  }
  
  // ========================================================================
  // UTILITIES
  // ========================================================================
  
  getStoreName(type) {
    // Geometry types: routes, poi, peaks, tracks
    const geometryTypes = ['routes', 'poi', 'peaks', 'tracks', 'photos'];
    return geometryTypes.includes(type) ? 'geometry' : 'metadata';
  }
  
  getTTL(type) {
    const ttls = {
      routes: 7 * 24 * 60 * 60 * 1000,      // 7 days
      poi: 7 * 24 * 60 * 60 * 1000,         // 7 days
      peaks: 30 * 24 * 60 * 60 * 1000,      // 30 days (rzadko się zmieniają)
      tracks: 1 * 24 * 60 * 60 * 1000,      // 1 day
      metadata: 1 * 60 * 60 * 1000,         // 1 hour
      user: 5 * 60 * 1000                   // 5 minutes
    };
    return ttls[type] || ttls.metadata;
  }
  
  isExpired(entry, ttl) {
    if (!entry || !entry.timestamp) return true;
    return (Date.now() - entry.timestamp) > ttl;
  }
  
  getStats() {
    return {
      memory: this.memoryCache.size,
      types: Array.from(new Set(
        Array.from(this.memoryCache.keys()).map(k => k.split('_')[0])
      ))
    };
  }
}

// Export
window.CacheManager = CacheManager;