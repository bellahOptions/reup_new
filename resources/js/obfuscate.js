const JavaScriptObfuscator = require('javascript-obfuscator');
const fs = require('fs');
const path = require('path');

const obfuscationOptions = {
    compact: true,
    controlFlowFlattening: true,
    controlFlowFlatteningThreshold: 0.75,
    deadCodeInjection: true,
    deadCodeInjectionThreshold: 0.4,
    debugProtection: false, // Set to true in production
    debugProtectionInterval: true,
    disableConsoleOutput: true,
    identifierNamesGenerator: 'hexadecimal',
    log: false,
    numbersToExpressions: true,
    renameGlobals: false,
    selfDefending: true,
    simplify: true,
    splitStrings: true,
    splitStringsChunkLength: 10,
    stringArray: true,
    stringArrayEncoding: ['rc4'],
    stringArrayIndexShift: true,
    stringArrayRotate: true,
    stringArrayShuffle: true,
    stringArrayWrappersCount: 2,
    stringArrayWrappersChainedCalls: true,
    stringArrayWrappersParametersMaxCount: 4,
    stringArrayWrappersType: 'function',
    stringArrayThreshold: 0.75,
    transformObjectKeys: true,
    unicodeEscapeSequence: false
};

function obfuscateFile(filePath) {
    try {
        const code = fs.readFileSync(filePath, 'utf-8');
        const obfuscatedCode = JavaScriptObfuscator.obfuscate(code, obfuscationOptions);
        
        const obfuscatedPath = filePath.replace('.js', '.obf.js');
        fs.writeFileSync(obfuscatedPath, obfuscatedCode.getObfuscatedCode());
        
        console.log(`✅ Obfuscated: ${path.basename(filePath)}`);
    } catch (error) {
        console.error(`❌ Error obfuscating ${filePath}:`, error.message);
    }
}

function processDirectory(directory) {
    const files = fs.readdirSync(directory);
    
    files.forEach(file => {
        const filePath = path.join(directory, file);
        const stat = fs.statSync(filePath);
        
        if (stat.isDirectory()) {
            processDirectory(filePath);
        } else if (file.endsWith('.js') && !file.includes('.obf.') && !file.includes('.min.')) {
            obfuscateFile(filePath);
        }
    });
}

// Obfuscate public/js directory
processDirectory(path.join(__dirname, 'public/js'));
console.log('🎉 JavaScript obfuscation complete!');