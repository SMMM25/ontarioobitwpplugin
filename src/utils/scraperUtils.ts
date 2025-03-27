// This file contains scraper utilities for the obituary scraper
// In a real implementation, this would handle the actual scraping logic

import { Obituary } from "./dataUtils";

interface ScraperConfig {
  regions: string[];
  frequency: string;
  maxAge: number;
  filterKeywords?: string[];
  startDate?: string;
  endDate?: string;
  retryAttempts?: number;
  userAgent?: string;
  timeout?: number;
  adaptiveMode?: boolean;
  // Remove sources property to avoid disclosing specific data sources
}

export const DEFAULT_SCRAPER_CONFIG: ScraperConfig = {
  regions: ["Toronto", "Ottawa", "Hamilton", "London", "Windsor"],
  frequency: "daily",
  maxAge: 7,
  retryAttempts: 3,
  timeout: 30000, // 30 seconds
  userAgent: "Mozilla/5.0 (compatible)", // Generic user agent
  adaptiveMode: true // Enable adaptive scraping by default
  // Remove specific sources list
};

// Enhanced function to simulate scraping obituaries from sources with better error handling
export const scrapeObituaries = async (
  config: ScraperConfig = DEFAULT_SCRAPER_CONFIG
): Promise<{ success: boolean; data: Obituary[] | null; message: string; errors?: Record<string, string> }> => {
  console.log("Scraping obituaries with enhanced privacy measures");
  
  // Track errors by region for better reporting
  const errors: Record<string, string> = {};
  const allObituaries: Obituary[] = [];
  
  // Process each region with individual error handling
  for (const region of config.regions) {
    try {
      console.log(`Processing region: ${region}`);
      
      // Simulate API delay with realistic variance (1-3 seconds)
      await new Promise((resolve) => setTimeout(resolve, 1000 + Math.random() * 2000));
      
      // Simulate collection process with retry logic
      let success = false;
      let attemptCount = 0;
      const maxAttempts = config.retryAttempts || 3;
      
      while (!success && attemptCount < maxAttempts) {
        attemptCount++;
        console.log(`Region ${region}: Attempt ${attemptCount} of ${maxAttempts}`);
        
        // Simulate connection success/failure with 80% success rate
        const connectionSuccess = Math.random() > 0.2;
        
        if (connectionSuccess) {
          success = true;
          console.log(`Region ${region}: Process successful on attempt ${attemptCount}`);
          
          // If adaptive mode is enabled, use this for better content processing
          if (config.adaptiveMode) {
            console.log(`Adaptive mode enabled: Optimizing for ${region}`);
            // In a real implementation, this would analyze content structure
            // and adapt processing without revealing sources
          }
          
          // Generate data for the region
          const regionObituaries = await generateDataForRegion(
            region, 
            Math.floor(Math.random() * 5) + 3, // 3-8 obituaries per region
            config
          );
          
          // Filter by date range if specified
          const filteredObituaries = filterObituariesByDateRange(regionObituaries, config);
          
          console.log(`Region ${region}: Found ${filteredObituaries.length} obituaries`);
          allObituaries.push(...filteredObituaries);
          
          // Process additional public data without revealing specific sources
          const additionalObituaries = await processAdditionalData(region, config);
          const filteredAdditionalObituaries = filterObituariesByDateRange(additionalObituaries, config);
          
          console.log(`Found ${filteredAdditionalObituaries.length} additional obituaries for ${region}`);
          allObituaries.push(...filteredAdditionalObituaries);
        } else {
          // Simulate exponential backoff for retries (200ms, 400ms, 800ms, etc.)
          const backoffTime = Math.pow(2, attemptCount) * 100;
          console.log(`Region ${region}: Connection failed, retrying in ${backoffTime}ms...`);
          await new Promise(resolve => setTimeout(resolve, backoffTime));
        }
      }
      
      if (!success) {
        errors[region] = `Failed to process after ${maxAttempts} attempts`;
        console.error(`Region ${region}: All attempts failed`);
      }
    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : String(error);
      errors[region] = errorMessage;
      console.error(`Region ${region}: Error during processing: ${errorMessage}`);
    }
  }
  
  // Analyze results
  const successfulRegions = config.regions.length - Object.keys(errors).length;
  
  if (successfulRegions === 0) {
    return {
      success: false,
      data: null,
      message: "Failed to process any regions. Check network connectivity.",
      errors
    };
  }
  
  // Detect and log potential structure changes while maintaining privacy
  if (config.adaptiveMode) {
    detectStructureChanges(allObituaries);
  }
  
  // Perform deduplication
  const dedupedObituaries = deduplicateObituaries(allObituaries);
  
  // Log statistics
  const stats = {
    totalRegions: config.regions.length,
    successfulRegions,
    totalObituaries: allObituaries.length,
    dedupedObituaries: dedupedObituaries.length,
    errorCount: Object.keys(errors).length
  };
  console.log("Processing completed with statistics:", stats);
  
  return {
    success: true,
    data: dedupedObituaries,
    message: `Successfully processed ${dedupedObituaries.length} obituaries from ${successfulRegions} of ${config.regions.length} regions`,
    errors: Object.keys(errors).length > 0 ? errors : undefined
  };
};

// Renamed function to hide source specifics
const processAdditionalData = async (
  region: string,
  config: ScraperConfig
): Promise<Obituary[]> => {
  const additionalObituaries: Obituary[] = [];
  
  try {
    console.log(`Processing additional data for ${region}`);
    await new Promise(resolve => setTimeout(resolve, 800 + Math.random() * 1500));
    
    // Generate generic obituaries (3-7)
    const dataCount = Math.floor(Math.random() * 5) + 3;
    const processedData = generateGenericObituaries(region, dataCount, "source1");
    
    console.log(`Found ${processedData.length} additional obituaries for ${region}`);
    additionalObituaries.push(...processedData);
  } catch (error) {
    console.error(`Error processing additional data for ${region}:`, error);
  }
  
  try {
    console.log(`Processing second data source for ${region}`);
    await new Promise(resolve => setTimeout(resolve, 700 + Math.random() * 1300));
    
    // Generate more generic obituaries (2-6)
    const secondaryCount = Math.floor(Math.random() * 5) + 2;
    const secondaryData = generateGenericObituaries(region, secondaryCount, "source2");
    
    console.log(`Found ${secondaryData.length} secondary obituaries for ${region}`);
    additionalObituaries.push(...secondaryData);
  } catch (error) {
    console.error(`Error processing secondary data for ${region}:`, error);
  }
  
  return additionalObituaries;
};

// Generate generic obituaries without revealing specific sources
const generateGenericObituaries = (region: string, count: number, sourceType: string): Obituary[] => {
  const obituaries: Obituary[] = [];
  const today = new Date();
  
  // Names for generating random obituaries
  const firstNames = [
    "Walter", "Grace", "Howard", "Ethel", "Ernest", "Florence", "Stanley", "Doris", 
    "Harry", "Irene", "Alfred", "Mildred", "Raymond", "Edith", "Eugene", "Ruth"
  ];
  
  const lastNames = [
    "Phillips", "Collins", "Wood", "Stewart", "Bennett", "Hayes", "Price", "Reed", 
    "Baker", "Perry", "Ward", "Watson", "Brooks", "Gray", "Sanders", "Price"
  ];
  
  for (let i = 0; i < count; i++) {
    const firstName = firstNames[Math.floor(Math.random() * firstNames.length)];
    const lastName = lastNames[Math.floor(Math.random() * lastNames.length)];
    
    const age = Math.floor(Math.random() * 30) + 60; // Ages 60-90
    
    // Generate a date of death within the last 7 days
    const daysAgo = Math.floor(Math.random() * 7);
    const deathDate = new Date(today);
    deathDate.setDate(today.getDate() - daysAgo);
    
    // Generate birth date based on age
    const birthYear = deathDate.getFullYear() - age;
    const birthMonth = Math.floor(Math.random() * 12);
    const birthDay = Math.floor(Math.random() * 28) + 1;
    const birthDate = new Date(birthYear, birthMonth, birthDay);
    
    // Create a generic source reference instead of a specific site
    const sourceIdentifier = `public-${sourceType}-${Date.now()}-${i}`;
    
    obituaries.push({
      id: sourceIdentifier,
      name: `${firstName} ${lastName}`,
      age: age,
      dateOfBirth: birthDate.toISOString().split('T')[0],
      dateOfDeath: deathDate.toISOString().split('T')[0],
      funeralHome: `${region} Memorial Services`, // Generic funeral home reference
      location: region,
      description: generateGenericDescription(firstName, lastName, age, region),
      sourceUrl: `https://memorial-services.info/${sourceIdentifier}`, // Generic URL that doesn't identify real source
      imageUrl: Math.random() > 0.3 ? `https://randomuser.me/api/portraits/${Math.random() > 0.5 ? 'men' : 'women'}/${Math.floor(Math.random() * 70) + 30}.jpg` : undefined
    });
  }
  
  return obituaries;
};

// Generate generic descriptions
const generateGenericDescription = (firstName: string, lastName: string, age: number, location: string): string => {
  const templates = [
    `With heavy hearts, we announce the passing of {firstName} {lastName}, {age}, of {location}. {firstName} leaves behind a loving family who will cherish their memory. A memorial service will be held at a local church on Saturday.`,
    
    `{firstName} {lastName}, {age}, passed away peacefully on {deathDate}. Born and raised in {location}, {firstName} was a devoted parent and grandparent who enjoyed gardening and community volunteering.`,
    
    `The family of {firstName} {lastName} sadly announces their passing at the age of {age}. A lifelong resident of {location}, {firstName} was known for their kindness and generosity.`,
    
    `{firstName} {lastName} of {location}, aged {age}, passed into eternal rest surrounded by loved ones. A celebration of life will be held next week at a local community center.`,
    
    `We mourn the loss of {firstName} {lastName}, {age}, who left us too soon. {firstName} was a pillar of the {location} community and will be remembered for their contributions to local charities.`
  ];
  
  const template = templates[Math.floor(Math.random() * templates.length)];
  return template
    .replace(/{firstName}/g, firstName)
    .replace(/{lastName}/g, lastName)
    .replace(/{age}/g, age.toString())
    .replace(/{location}/g, location)
    .replace(/{deathDate}/g, new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }));
};

// Filter obituaries by date range
const filterObituariesByDateRange = (obituaries: Obituary[], config: ScraperConfig): Obituary[] => {
  if (!config.startDate && !config.endDate) {
    return obituaries;
  }
  
  const startDate = config.startDate ? new Date(config.startDate) : new Date(0);
  const endDate = config.endDate ? new Date(config.endDate) : new Date();
  
  return obituaries.filter(obit => {
    const deathDate = new Date(obit.dateOfDeath);
    return deathDate >= startDate && deathDate <= endDate;
  });
};

// Detect potential structure changes in obituary sources
const detectStructureChanges = (obituaries: Obituary[]): void => {
  // This would be a sophisticated algorithm in production
  // Here's a simplified simulation:
  
  // Check for patterns that might indicate structure changes
  const regionCounts: Record<string, number> = {};
  const datePatterns: Record<string, number> = {};
  
  obituaries.forEach(obit => {
    // Count by region
    regionCounts[obit.location] = (regionCounts[obit.location] || 0) + 1;
    
    // Analyze date format patterns
    const datePattern = obit.dateOfDeath.match(/\d{4}-\d{2}-\d{2}/) ? "iso" : "other";
    datePatterns[datePattern] = (datePatterns[datePattern] || 0) + 1;
  });
  
  // Look for anomalies that might indicate structure changes
  const regions = Object.keys(regionCounts);
  
  regions.forEach(region => {
    const count = regionCounts[region];
    const average = obituaries.length / regions.length;
    
    // If a region has significantly fewer entries than average, it might indicate a structure change
    if (count < average * 0.5 && count > 0) {
      console.warn(`Possible structure change detected for ${region}: Only ${count} obituaries found (vs. avg ${average.toFixed(1)})`);
    }
  });
  
  // Check for date format inconsistencies
  if (Object.keys(datePatterns).length > 1) {
    console.warn("Possible structure change detected: Inconsistent date formats found");
  }
};

// Generate data for a region
const generateDataForRegion = async (
  region: string,
  count: number,
  config: ScraperConfig
): Promise<Obituary[]> => {
  const obituaries: Obituary[] = [];
  const today = new Date();
  
  // Names for generating random obituaries
  const firstNames = [
    "James", "Mary", "John", "Patricia", "Robert", "Jennifer", "Michael", "Linda", 
    "William", "Elizabeth", "David", "Barbara", "Richard", "Susan", "Joseph", "Jessica",
    "Thomas", "Sarah", "Charles", "Karen", "Christopher", "Margaret", "Daniel", "Nancy",
    "Matthew", "Lisa", "Anthony", "Betty", "Donald", "Dorothy", "Mark", "Sandra"
  ];
  
  const lastNames = [
    "Smith", "Johnson", "Williams", "Brown", "Jones", "Miller", "Davis", "Garcia", 
    "Rodriguez", "Wilson", "Martinez", "Anderson", "Taylor", "Thomas", "Hernandez", "Moore",
    "Martin", "Jackson", "Thompson", "White", "Lopez", "Lee", "Gonzalez", "Harris",
    "Clark", "Lewis", "Robinson", "Walker", "Perez", "Hall", "Young", "Allen"
  ];
  
  // Use generic funeral home names instead of specific ones
  const funeralHomes = {
    "Toronto": ["Memorial Services", "City Funeral Home", "Metropolitan Services"],
    "Ottawa": ["Memorial Chapel", "Capital Services", "City Memorial"],
    "Hamilton": ["Memorial Gardens", "Mountain Memorial", "Regional Services"],
    "London": ["Memorial Center", "City Services", "Community Chapel"],
    "Windsor": ["Memorial Gardens", "Riverside Services", "County Chapel"]
  };
  
  for (let i = 0; i < count; i++) {
    const firstName = firstNames[Math.floor(Math.random() * firstNames.length)];
    const lastName = lastNames[Math.floor(Math.random() * lastNames.length)];
    
    const age = Math.floor(Math.random() * 50) + 40; // Ages 40-90
    
    // Generate a date of death within the configured maxAge
    const maxAgeDays = config.maxAge || 7;
    const daysAgo = Math.floor(Math.random() * maxAgeDays);
    const deathDate = new Date(today);
    deathDate.setDate(today.getDate() - daysAgo);
    
    // Generate birth date based on age
    const birthYear = deathDate.getFullYear() - age;
    const birthMonth = Math.floor(Math.random() * 12);
    const birthDay = Math.floor(Math.random() * 28) + 1;
    const birthDate = new Date(birthYear, birthMonth, birthDay);
    
    const regionalFuneralHomes = funeralHomes[region as keyof typeof funeralHomes] || ["Memorial Services"];
    const funeralHome = regionalFuneralHomes[Math.floor(Math.random() * regionalFuneralHomes.length)];
    
    const description = generateObituaryDescription(firstName, lastName, age, region);
    
    // Generate a generic ID that doesn't reveal source
    const genericId = `obit-${Date.now()}-${i}`;
    
    obituaries.push({
      id: genericId,
      name: `${firstName} ${lastName}`,
      age: age,
      dateOfBirth: birthDate.toISOString().split('T')[0],
      dateOfDeath: deathDate.toISOString().split('T')[0],
      funeralHome: funeralHome,
      location: region,
      description: description,
      sourceUrl: `https://memorial-records.org/obituaries/${lastName.toLowerCase()}-${firstName.toLowerCase()}-${genericId}`,
      imageUrl: Math.random() > 0.3 ? `https://randomuser.me/api/portraits/${Math.random() > 0.5 ? 'men' : 'women'}/${Math.floor(Math.random() * 70)}.jpg` : undefined
    });
  }
  
  return obituaries;
};

// Function to retrieve historical data with enhanced privacy
export const getHistoricalData = async (
  config: ScraperConfig = DEFAULT_SCRAPER_CONFIG
): Promise<{ success: boolean; data: Obituary[] | null; message: string; errors?: Record<string, string> }> => {
  console.log("Retrieving historical data with privacy measures...");
  
  // Calculate date range for previous month
  const today = new Date();
  const lastMonth = new Date(today);
  lastMonth.setMonth(today.getMonth() - 1);
  
  const startDate = new Date(lastMonth.getFullYear(), lastMonth.getMonth(), 1);
  const endDate = new Date(today.getFullYear(), today.getMonth(), 0);
  
  // Update config with date range
  const historicalConfig: ScraperConfig = {
    ...config,
    startDate: startDate.toISOString().split('T')[0],
    endDate: endDate.toISOString().split('T')[0],
    retryAttempts: 5, // More retries for historical data
    timeout: 60000, // Longer timeout for historical retrieval
  };
  
  console.log("Historical date range:", historicalConfig.startDate, "to", historicalConfig.endDate);
  
  // Track errors by region
  const errors: Record<string, string> = {};
  const allObituaries: Obituary[] = [];
  
  // Process each region separately
  for (const region of config.regions) {
    try {
      console.log(`Processing historical data for region: ${region}`);
      
      // Simulate connection with retry logic
      let success = false;
      let attemptCount = 0;
      const maxAttempts = historicalConfig.retryAttempts || 5;
      
      while (!success && attemptCount < maxAttempts) {
        attemptCount++;
        console.log(`Region ${region} historical data: Attempt ${attemptCount} of ${maxAttempts}`);
        
        // 85% success rate for historical data
        if (Math.random() > 0.15) {
          success = true;
          
          // Generate 10-20 mock obituaries for this region and date range
          const count = Math.floor(Math.random() * 10) + 10;
          const regionObituaries = await generateDataForDateRange(
            startDate,
            endDate,
            region,
            count
          );
          
          console.log(`Region ${region}: Found ${regionObituaries.length} historical obituaries`);
          allObituaries.push(...regionObituaries);
        } else {
          // Exponential backoff
          const backoffTime = Math.pow(2, attemptCount) * 500;
          console.log(`Region ${region}: Historical data retrieval failed, retrying in ${backoffTime}ms...`);
          await new Promise(resolve => setTimeout(resolve, backoffTime));
        }
      }
      
      if (!success) {
        errors[region] = `Failed to retrieve historical data after ${maxAttempts} attempts`;
      }
      
    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : String(error);
      errors[region] = errorMessage;
      console.error(`Region ${region}: Error retrieving historical data: ${errorMessage}`);
    }
    
    // Small delay between regions
    await new Promise(resolve => setTimeout(resolve, 1000));
  }
  
  // Analyze results
  const successfulRegions = config.regions.length - Object.keys(errors).length;
  
  if (successfulRegions === 0) {
    return {
      success: false,
      data: null,
      message: "Failed to retrieve historical data from any regions.",
      errors
    };
  }
  
  // Deduplicate obituaries
  const dedupedObituaries = deduplicateObituaries(allObituaries);
  
  return {
    success: true,
    data: dedupedObituaries,
    message: `Successfully retrieved ${dedupedObituaries.length} historical obituaries from ${successfulRegions} of ${config.regions.length} regions`,
    errors: Object.keys(errors).length > 0 ? errors : undefined
  };
};

// Helper function to generate obituary data for a specific date range
const generateDataForDateRange = async (
  startDate: Date,
  endDate: Date,
  region: string,
  count: number
): Promise<Obituary[]> => {
  const obituaries: Obituary[] = [];
  const dayCount = Math.floor((endDate.getTime() - startDate.getTime()) / (24 * 60 * 60 * 1000));
  
  // Names for generating random obituaries
  const firstNames = [
    "James", "Mary", "John", "Patricia", "Robert", "Jennifer", "Michael", "Linda", 
    "William", "Elizabeth", "David", "Barbara", "Richard", "Susan", "Joseph", "Jessica"
  ];
  
  const lastNames = [
    "Smith", "Johnson", "Williams", "Brown", "Jones", "Miller", "Davis", "Garcia", 
    "Rodriguez", "Wilson", "Martinez", "Anderson", "Taylor", "Thomas", "Hernandez", "Moore"
  ];
  
  // Use generic funeral home names
  const funeralHomes = {
    "Toronto": ["Memorial Services", "City Funeral Home", "Metropolitan Services"],
    "Ottawa": ["Memorial Chapel", "Capital Services", "City Memorial"],
    "Hamilton": ["Memorial Gardens", "Mountain Memorial", "Regional Services"],
    "London": ["Memorial Center", "City Services", "Community Chapel"],
    "Windsor": ["Memorial Gardens", "Riverside Services", "County Chapel"]
  };
  
  // Distribute obituaries across the date range
  for (let i = 0; i < count; i++) {
    // Pick a random day within the range
    const randomDayOffset = Math.floor(Math.random() * dayCount);
    const date = new Date(startDate);
    date.setDate(startDate.getDate() + randomDayOffset);
    
    const firstName = firstNames[Math.floor(Math.random() * firstNames.length)];
    const lastName = lastNames[Math.floor(Math.random() * lastNames.length)];
    
    const age = Math.floor(Math.random() * 50) + 40; // Ages 40-90
    const birthYear = date.getFullYear() - age;
    const birthMonth = Math.floor(Math.random() * 12);
    const birthDay = Math.floor(Math.random() * 28) + 1;
    const dateOfBirth = `${birthYear}-${(birthMonth + 1).toString().padStart(2, '0')}-${birthDay.toString().padStart(2, '0')}`;
    
    const regionalFuneralHomes = funeralHomes[region as keyof typeof funeralHomes] || ["Memorial Services"];
    const funeralHome = regionalFuneralHomes[Math.floor(Math.random() * regionalFuneralHomes.length)];
    
    // Generate a generic ID that doesn't reveal source
    const genericId = `historical-${date.getTime()}-${i}`;
    
    obituaries.push({
      id: genericId,
      name: `${firstName} ${lastName}`,
      age: age,
      dateOfBirth: dateOfBirth,
      dateOfDeath: date.toISOString().split('T')[0],
      funeralHome: funeralHome,
      location: region,
      description: generateObituaryDescription(firstName, lastName, age, region),
      sourceUrl: `https://memorial-archives.org/records/${lastName.toLowerCase()}-${firstName.toLowerCase()}-${genericId}`,
      imageUrl: Math.random() > 0.3 ? `https://randomuser.me/api/portraits/${Math.random() > 0.5 ? 'men' : 'women'}/${Math.floor(Math.random() * 70)}.jpg` : undefined
    });
  }
  
  return obituaries;
};

// Generate a generic obituary description
const generateObituaryDescription = (firstName: string, lastName: string, age: number, location: string): string => {
  const templates = [
    `It is with great sadness that the family of {firstName} {lastName} announces their passing on {deathDate} at the age of {age}. {firstName} will be lovingly remembered by their family and friends in {location} and surrounding areas.`,
    
    `{firstName} {lastName}, age {age}, passed away peacefully surrounded by loved ones. Born and raised in {location}, {firstName} was known for their kindness and generosity to all who knew them.`,
    
    `The family announces with sorrow the death of {firstName} {lastName} of {location} at the age of {age}. {firstName} leaves behind many friends and family members who will deeply miss their presence in their lives.`,
    
    `After a life well-lived, {firstName} {lastName} passed away at the age of {age}. A long-time resident of {location}, {firstName} was active in the community and will be remembered for their contributions to local charities.`,
    
    `{firstName} {lastName} passed away peacefully at home at the age of {age}. Born in {location}, {firstName} was a beloved member of the community and will be deeply missed by all who knew them.`
  ];
  
  const template = templates[Math.floor(Math.random() * templates.length)];
  return template
    .replace(/{firstName}/g, firstName)
    .replace(/{lastName}/g, lastName)
    .replace(/{age}/g, age.toString())
    .replace(/{location}/g, location)
    .replace(/{deathDate}/g, new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }));
};

// Enhanced function to identify duplicates with fuzzy matching
export const isDuplicate = (
  newObituary: Partial<Obituary>,
  existingObituaries: Obituary[]
): boolean => {
  return existingObituaries.some(existing => {
    // Exact match by name and date
    if (existing.name.toLowerCase() === newObituary.name?.toLowerCase() && 
        existing.dateOfDeath === newObituary.dateOfDeath) {
      return true;
    }
    
    // Fuzzy name match (last name and first initial) with same date
    if (newObituary.name && existing.dateOfDeath === newObituary.dateOfDeath) {
      const newNameParts = newObituary.name.toLowerCase().split(' ');
      const existingNameParts = existing.name.toLowerCase().split(' ');
      
      if (newNameParts.length > 1 && existingNameParts.length > 1) {
        // Check if last names match and first initials match
        const newLastName = newNameParts[newNameParts.length - 1];
        const existingLastName = existingNameParts[existingNameParts.length - 1];
        
        const newFirstInitial = newNameParts[0].charAt(0);
        const existingFirstInitial = existingNameParts[0].charAt(0);
        
        if (newLastName === existingLastName && newFirstInitial === existingFirstInitial) {
          return true;
        }
      }
    }
    
    return false;
  });
};

// Deduplicate a list of obituaries
const deduplicateObituaries = (obituaries: Obituary[]): Obituary[] => {
  const uniqueObituaries: Obituary[] = [];
  
  for (const obituary of obituaries) {
    if (!isDuplicate(obituary, uniqueObituaries)) {
      uniqueObituaries.push(obituary);
    }
  }
  
  return uniqueObituaries;
};

// Enhanced validation function for obituary data
export const validateObituary = (
  obituary: Partial<Obituary>
): { isValid: boolean; errors: string[]; warnings: string[] } => {
  const errors: string[] = [];
  const warnings: string[] = [];
  
  // Required fields
  if (!obituary.name) {
    errors.push("Missing name");
  } else if (obituary.name.length < 3) {
    errors.push("Name is too short");
  }
  
  if (!obituary.dateOfDeath) {
    errors.push("Missing date of death");
  } else {
    // Validate date format
    const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
    if (!dateRegex.test(obituary.dateOfDeath)) {
      errors.push("Invalid date format for date of death (should be YYYY-MM-DD)");
    } else {
      // Check if date is in the future
      const deathDate = new Date(obituary.dateOfDeath);
      if (deathDate > new Date()) {
        errors.push("Date of death cannot be in the future");
      }
    }
  }
  
  if (!obituary.location) {
    errors.push("Missing location");
  }
  
  if (!obituary.funeralHome) {
    errors.push("Missing funeral home");
  }
  
  // Optional fields with validation
  if (obituary.dateOfBirth) {
    const birthDateRegex = /^\d{4}-\d{2}-\d{2}$/;
    if (!birthDateRegex.test(obituary.dateOfBirth)) {
      errors.push("Invalid date format for date of birth (should be YYYY-MM-DD)");
    } else if (obituary.dateOfDeath) {
      // Check if birth date is after death date
      const birthDate = new Date(obituary.dateOfBirth);
      const deathDate = new Date(obituary.dateOfDeath);
      if (birthDate > deathDate) {
        errors.push("Date of birth cannot be after date of death");
      }
    }
  } else {
    warnings.push("Date of birth is missing");
  }
  
  if (!obituary.description) {
    errors.push("Missing description");
  } else if (obituary.description.length < 20) {
    warnings.push("Description is very short");
  }
  
  if (obituary.age !== undefined) {
    if (isNaN(Number(obituary.age))) {
      errors.push("Age must be a number");
    } else if (Number(obituary.age) > 120 || Number(obituary.age) < 0) {
      errors.push("Age must be between 0 and 120");
    }
  } else if (obituary.dateOfBirth && obituary.dateOfDeath) {
    warnings.push("Age is missing but could be calculated from birth and death dates");
  }
  
  if (obituary.sourceUrl && !obituary.sourceUrl.startsWith('http')) {
    warnings.push("Source URL should start with http:// or https://");
  }
  
  return {
    isValid: errors.length === 0,
    errors,
    warnings
  };
};

// Function to recover from common errors
export const attemptErrorRecovery = async (
  error: Error,
  region: string,
  config: ScraperConfig
): Promise<{ recovered: boolean; message: string }> => {
  console.log(`Attempting to recover from error in ${region}:`, error.message);
  
  // Simulate different recovery strategies based on error type
  const errorMessage = error.message.toLowerCase();
  
  // Network timeout
  if (errorMessage.includes('timeout') || errorMessage.includes('timed out')) {
    console.log(`Recovery strategy: Increasing timeout for ${region}`);
    // In a real implementation, we would adjust the timeout and retry
    await new Promise(resolve => setTimeout(resolve, 1000));
    return { recovered: true, message: "Recovered by increasing connection timeout" };
  }
  
  // Structure change detection
  if (errorMessage.includes('selector') || errorMessage.includes('element not found')) {
    console.log(`Recovery strategy: Attempting alternative selectors for ${region}`);
    // In a real implementation, we would try alternative selectors
    await new Promise(resolve => setTimeout(resolve, 1500));
    return { recovered: Math.random() > 0.3, message: "Attempted recovery with alternative selectors" };
  }
  
  // Rate limiting
  if (errorMessage.includes('rate') || errorMessage.includes('limit') || errorMessage.includes('429')) {
    console.log(`Recovery strategy: Implementing backoff delay for ${region}`);
    // In a real implementation, we would add a significant delay before retrying
    await new Promise(resolve => setTimeout(resolve, 2000));
    return { recovered: true, message: "Recovered from rate limiting with backoff delay" };
  }
  
  // Default recovery attempt
  console.log(`Generic recovery attempt for ${region}`);
  await new Promise(resolve => setTimeout(resolve, 1000));
  return { recovered: Math.random() > 0.5, message: "Attempted generic recovery strategy" };
};

// Enhanced function to format data for WordPress with privacy in mind
export const formatForWordPress = (obituaries: Obituary[]): unknown => {
  // Transform the data into a format compatible with WordPress posts
  // without revealing source information
  
  return obituaries.map(obit => ({
    post_title: obit.name,
    post_content: obit.description,
    post_status: "publish",
    post_type: "obituary", // Custom post type
    meta: {
      obituary_date_of_death: obit.dateOfDeath,
      obituary_date_of_birth: obit.dateOfBirth || "",
      obituary_age: obit.age || "",
      obituary_funeral_home: obit.funeralHome,
      obituary_location: obit.location,
      // Omit original source URL to protect privacy
      obituary_source: "Public Records" // Generic source description
    }
  }));
};
